<?php
/**
 * FleetGo — Google Gemini client and grounded context builders.
 * The API key lives in gemini_config.local.php (gitignored).
 */

function gemini_config(): array {
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $path = __DIR__ . '/gemini_config.local.php';
    $loaded = is_file($path) ? include $path : [];
    $cfg = is_array($loaded) ? $loaded : [];
    return $cfg;
}

function gemini_api_key(): string {
    return trim((string)(gemini_config()['api_key'] ?? ''));
}

function gemini_model(): string {
    $model = trim((string)(gemini_config()['model'] ?? ''));
    if ($model === '' || $model === 'gemini-2.5-flash') {
        return 'gemini-3.8-flash';
    }
    return $model;
}

function gemini_is_configured(): bool {
    return gemini_api_key() !== '';
}

function gemini_clip(string $text, int $max): string {
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max);
    }
    return substr($text, 0, $max);
}

function gemini_ensure_listing_column(mysqli $conn): void {
    try {
        $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'listing_description' LIMIT 1");
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$exists) {
            $conn->query("ALTER TABLE vehicles ADD COLUMN listing_description TEXT NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('gemini listing column: ' . $e->getMessage());
    }
}

/**
 * @param array<int,array{type:string,content:array<int,array{type:string,text:string}>}> $input
 * @return array{ok:bool,text:string,error:string}
 */
function gemini_model_chain(): array {
    $chain = [gemini_model(), 'gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-3.5-flash'];
    $out = [];
    foreach ($chain as $model) {
        if ($model !== '' && !in_array($model, $out, true)) {
            $out[] = $model;
        }
    }
    return $out;
}

function gemini_should_try_next(int $status, string $message, int $errno): bool {
    if ($errno !== 0) {
        return true;
    }
    $hay = strtolower($message);
    if (in_array($status, [429, 500, 502, 503, 504], true)) {
        return true;
    }
    foreach (['high demand', 'try again later', 'unavailable', 'overloaded', 'resource exhausted', 'not found', 'no longer available', 'not supported'] as $needle) {
        if (str_contains($hay, $needle)) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<int,array{type:string,content:array<int,array{type:string,text:string}>}> $input
 * @return array{ok:bool,text:string,error:string,status:int,errno:int}
 */
function gemini_request(string $model, string $system, array $input, int $maxTokens, bool $withThinking): array {
    $key = gemini_api_key();
    $generation = ['max_output_tokens' => $maxTokens];
    if ($withThinking) {
        $generation['thinking_level'] = 'low';
    }
    $body = [
        'model' => $model,
        'system_instruction' => $system,
        'input' => $input,
        'store' => false,
        'generation_config' => $generation,
    ];

    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/interactions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $key,
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $cerr = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        $msg = $cerr !== '' ? $cerr : 'Could not reach the Gemini API.';
        return ['ok' => false, 'text' => '', 'error' => str_replace($key, '[key]', $msg), 'status' => $status, 'errno' => $errno];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'text' => '', 'error' => 'Gemini returned an unreadable response.', 'status' => $status, 'errno' => 0];
    }
    if ($status >= 400 || isset($json['error'])) {
        $msg = (string)($json['error']['message'] ?? ('Gemini request failed (' . $status . ').'));
        return ['ok' => false, 'text' => '', 'error' => str_replace($key, '[key]', $msg), 'status' => $status, 'errno' => 0];
    }

    $text = gemini_interaction_text($json);
    if ($text === '') {
        $statusName = (string)($json['status'] ?? '');
        $hint = $statusName !== '' ? (' No text was returned (' . $statusName . ').') : ' No text was returned.';
        return ['ok' => false, 'text' => '', 'error' => 'Gemini did not return a summary.' . $hint, 'status' => $status, 'errno' => 0];
    }
    return ['ok' => true, 'text' => $text, 'error' => '', 'status' => $status, 'errno' => 0];
}

function gemini_generate(string $system, array $input, float $temperature = 0.4, int $maxTokens = 700): array {
    $key = gemini_api_key();
    if ($key === '') {
        return ['ok' => false, 'text' => '', 'error' => 'Gemini is not configured yet. Paste your API key into includes/gemini_config.local.php.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'text' => '', 'error' => 'PHP cURL is not enabled in XAMPP.'];
    }

    $last = ['ok' => false, 'text' => '', 'error' => 'Gemini did not respond.'];
    foreach (gemini_model_chain() as $model) {
        $result = gemini_request($model, $system, $input, $maxTokens, true);
        if (!$result['ok'] && str_contains(strtolower($result['error']), 'thinking_level')) {
            $result = gemini_request($model, $system, $input, $maxTokens, false);
        }
        if ($result['ok']) {
            return ['ok' => true, 'text' => gemini_plain($result['text']), 'error' => ''];
        }
        $last = ['ok' => false, 'text' => '', 'error' => $result['error']];
        if (!gemini_should_try_next($result['status'], $result['error'], $result['errno'])) {
            break;
        }
    }
    return $last;
}

function gemini_plain(string $text): string {
    $text = preg_replace('/\*\*(.*?)\*\*/s', '$1', $text) ?? $text;
    $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;
    $text = str_replace(['*', '`'], '', $text);
    return trim($text);
}

function gemini_interaction_text(array $json): string {
    if (isset($json['output_text']) && is_string($json['output_text'])) {
        return trim($json['output_text']);
    }
    $text = '';
    $steps = $json['steps'] ?? [];
    if (!is_array($steps)) {
        return '';
    }
    foreach ($steps as $step) {
        if (!is_array($step) || ($step['type'] ?? '') !== 'model_output') {
            continue;
        }
        $blocks = $step['content'] ?? [];
        if (!is_array($blocks)) {
            continue;
        }
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $text .= (string)$block['text'];
            }
        }
    }
    return trim($text);
}

/**
 * @param array<int,array{role?:string,text?:string}> $history
 * @return array<int,array{type:string,content:array<int,array{type:string,text:string}>}>
 */
function gemini_contents_from_history(array $history, string $latestUserText): array {
    $input = [];
    $kept = array_slice($history, -12);
    foreach ($kept as $turn) {
        if (!is_array($turn)) {
            continue;
        }
        $type = ($turn['role'] ?? '') === 'model' ? 'model_output' : 'user_input';
        $text = gemini_clip((string)($turn['text'] ?? ''), 2000);
        if ($text === '') {
            continue;
        }
        $input[] = ['type' => $type, 'content' => [['type' => 'text', 'text' => $text]]];
    }
    $input[] = ['type' => 'user_input', 'content' => [['type' => 'text', 'text' => gemini_clip($latestUserText, 4000)]]];
    return $input;
}

function gemini_policy_brief(): string {
    return implode("\n", [
        'The FleetGo office is in Gingoog City, Barangay Test Only. Prices are in Philippine pesos. Daily rates are quoted as a Cagayan de Oro rate and an outside-CDO rate.',
        'A customer must have an approved profile before booking. Use only the rates in the vehicle list.',
        'Late return fee: (daily rate ÷ 24) × hours late × 1.25.',
        'Fuel is charged from the return fuel level. Washing fees depend on the wash type (light, full, or interior and exterior).',
        'Built-in promos, applied automatically when the customer qualifies: Welcome 7% for a first booking, Loyalty 5% after 3 completed bookings, Long-term 6% for 5 or more days, Seasonal 4% for 3 to 7 days. Business customers receive half of those percentages.',
        'Do not promise a discount unless it is listed above or on the specific vehicle.',
    ]);
}

function gemini_report_context(mysqli $conn, string $from, string $to, int $vehicleId, string $tab): array {
    $ctx = [
        'company' => 'FleetGo',
        'currency' => 'PHP',
        'focus' => $tab !== '' ? $tab : 'earnings',
        'period' => ['from' => $from, 'to' => $to],
        'vehicle' => 'All vehicles',
    ];

    if ($vehicleId > 0) {
        $stmt = $conn->prepare('SELECT make_model, plate_no FROM vehicles WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $vehicleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $ctx['vehicle'] = trim(($row['make_model'] ?? '') . ' ' . ($row['plate_no'] ?? ''));
        }
    }

    $vehSql = $vehicleId > 0 ? ' AND vehicle_id = ?' : '';
    $sql = "
        SELECT
            COUNT(*) AS total_rentals,
            SUM(status = 'completed') AS completed_rentals,
            SUM(status IN ('ongoing','reserved')) AS active_rentals,
            SUM(status = 'cancelled') AS cancelled_rentals,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_cost ELSE 0 END), 0) AS earnings,
            COALESCE(AVG(CASE WHEN status <> 'cancelled' THEN total_cost END), 0) AS avg_earning
        FROM rentals
        WHERE DATE(created_at) BETWEEN ? AND ?{$vehSql}
    ";
    $stmt = $conn->prepare($sql);
    if ($vehicleId > 0) {
        $stmt->bind_param('ssi', $from, $to, $vehicleId);
    } else {
        $stmt->bind_param('ss', $from, $to);
    }
    $stmt->execute();
    $sum = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $ctx['rentals'] = [
        'total' => (int)($sum['total_rentals'] ?? 0),
        'completed' => (int)($sum['completed_rentals'] ?? 0),
        'active' => (int)($sum['active_rentals'] ?? 0),
        'cancelled' => (int)($sum['cancelled_rentals'] ?? 0),
        'earnings' => round((float)($sum['earnings'] ?? 0), 2),
        'average_earning' => round((float)($sum['avg_earning'] ?? 0), 2),
    ];

    $ctx['maintenance'] = ['jobs' => 0, 'expenses' => 0.0];
    try {
        $mSql = "
            SELECT COUNT(*) AS jobs,
                   COALESCE(SUM((CASE WHEN COALESCE(cost,0) > 0 THEN cost ELSE COALESCE(estimated_cost,0) END) + COALESCE(washing_cost,0)), 0) AS expenses
            FROM maintenance
            WHERE DATE(schedule_date) BETWEEN ? AND ?" . ($vehicleId > 0 ? ' AND vehicle_id = ?' : '');
        $stmt = $conn->prepare($mSql);
        if ($vehicleId > 0) {
            $stmt->bind_param('ssi', $from, $to, $vehicleId);
        } else {
            $stmt->bind_param('ss', $from, $to);
        }
        $stmt->execute();
        $m = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $ctx['maintenance'] = [
            'jobs' => (int)($m['jobs'] ?? 0),
            'expenses' => round((float)($m['expenses'] ?? 0), 2),
        ];
    } catch (Throwable $e) {
        $ctx['maintenance'] = ['jobs' => 0, 'expenses' => 0.0];
    }
    $ctx['net_income'] = round($ctx['rentals']['earnings'] - $ctx['maintenance']['expenses'], 2);

    $ctx['top_vehicles'] = [];
    try {
        $topSql = "
            SELECT v.make_model, v.plate_no,
                   COUNT(*) AS rentals,
                   COALESCE(SUM(CASE WHEN r.status <> 'cancelled' THEN r.total_cost ELSE 0 END), 0) AS earnings
            FROM rentals r
            JOIN vehicles v ON v.id = r.vehicle_id
            WHERE DATE(r.created_at) BETWEEN ? AND ?" . ($vehicleId > 0 ? ' AND r.vehicle_id = ?' : '') . "
            GROUP BY v.id, v.make_model, v.plate_no
            ORDER BY earnings DESC
            LIMIT 5
        ";
        $stmt = $conn->prepare($topSql);
        if ($vehicleId > 0) {
            $stmt->bind_param('ssi', $from, $to, $vehicleId);
        } else {
            $stmt->bind_param('ss', $from, $to);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ctx['top_vehicles'][] = [
                'vehicle' => trim(($row['make_model'] ?? '') . ' ' . ($row['plate_no'] ?? '')),
                'rentals' => (int)$row['rentals'],
                'earnings' => round((float)$row['earnings'], 2),
            ];
        }
        $stmt->close();
    } catch (Throwable $e) {
        $ctx['top_vehicles'] = [];
    }

    return $ctx;
}

function gemini_admin_context(mysqli $conn): array {
    $fleet = gemini_fleet_availability($conn, true);
    $ctx = [
        'company' => 'FleetGo',
        'currency' => 'PHP',
        'as_of' => date('Y-m-d'),
        'how_to_read' => 'available_now can be booked today. busy_today is already booked through free_after. Ignore a stale rented label when the vehicle is in available_now.',
        'available_now' => $fleet['available_now'],
        'busy_today' => $fleet['busy_today'],
    ];

    $ctx['stored_status_counts'] = [];
    $res = $conn->query("SELECT COALESCE(current_status,'unknown') AS status, COUNT(*) AS total FROM vehicles GROUP BY current_status");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $ctx['stored_status_counts'][] = ['status' => (string)$row['status'], 'count' => (int)$row['total']];
        }
    }

    $ctx['rentals_last_90_days'] = [];
    $res = $conn->query("SELECT status, COUNT(*) AS total FROM rentals WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY status");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $ctx['rentals_last_90_days'][] = ['status' => (string)$row['status'], 'count' => (int)$row['total']];
        }
    }

    $earn = $conn->query("SELECT COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_cost ELSE 0 END),0) AS earnings, COUNT(*) AS rentals FROM rentals WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $er = $earn ? $earn->fetch_assoc() : [];
    $ctx['last_30_days'] = [
        'rentals' => (int)($er['rentals'] ?? 0),
        'earnings' => round((float)($er['earnings'] ?? 0), 2),
    ];

    $ctx['recent_rentals'] = [];
    $recent = $conn->query("
        SELECT u.full_name AS customer, v.make_model, v.plate_no, r.start_date, r.end_date, r.status, r.total_cost, r.balance_due
        FROM rentals r
        JOIN users u ON u.id = r.customer_id
        JOIN vehicles v ON v.id = r.vehicle_id
        ORDER BY r.id DESC
        LIMIT 12
    ");
    if ($recent) {
        while ($row = $recent->fetch_assoc()) {
            $ctx['recent_rentals'][] = [
                'customer' => (string)$row['customer'],
                'vehicle' => trim(($row['make_model'] ?? '') . ' ' . ($row['plate_no'] ?? '')),
                'start' => (string)$row['start_date'],
                'end' => (string)$row['end_date'],
                'status' => (string)$row['status'],
                'total' => round((float)$row['total_cost'], 2),
                'balance_due' => round((float)$row['balance_due'], 2),
            ];
        }
    }

    $ctx['upcoming_maintenance'] = [];
    try {
        $maint = $conn->query("
            SELECT v.make_model, v.plate_no, m.schedule_date, m.status
            FROM maintenance m
            JOIN vehicles v ON v.id = m.vehicle_id
            WHERE m.schedule_date >= CURDATE() AND m.status <> 'completed'
            ORDER BY m.schedule_date ASC
            LIMIT 8
        ");
        if ($maint) {
            while ($row = $maint->fetch_assoc()) {
                $ctx['upcoming_maintenance'][] = [
                    'vehicle' => trim(($row['make_model'] ?? '') . ' ' . ($row['plate_no'] ?? '')),
                    'date' => (string)$row['schedule_date'],
                    'status' => (string)$row['status'],
                ];
            }
        }
    } catch (Throwable $e) {
        $ctx['upcoming_maintenance'] = [];
    }

    return $ctx;
}

function gemini_vehicle_photo(?string $photo): string {
    $name = basename(str_replace('\\', '/', trim((string)$photo)));
    $full = dirname(__DIR__) . '/assets/vehicles/' . $name;
    if ($name !== '' && $name !== '.' && is_file($full)) {
        return 'assets/vehicles/' . $name;
    }
    return 'assets/vehicles/images.jpeg';
}

function gemini_vehicle_card(array $row, bool $includePlate): array {
    $card = [
        'name' => (string)($row['make_model'] ?? ''),
        'type' => (string)($row['vehicle_type'] ?? ''),
        'seats' => (int)($row['seats'] ?? 0),
        'transmission' => (string)($row['transmission'] ?? ''),
        'daily_rate_cdo' => round((float)($row['daily_rate_cdo'] ?? 0), 2),
        'daily_rate_outside_cdo' => round((float)($row['daily_rate_outside_cdo'] ?? 0), 2),
    ];
    if (!empty($row['has_promo'])) {
        $card['discount'] = (string)($row['promo_label'] ?? '');
        $card['price_now'] = round((float)($row['price_now'] ?? 0), 2);
    }
    if ($includePlate) {
        $card['plate'] = (string)($row['plate_no'] ?? '');
    }
    return $card;
}

function gemini_public_catalog(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!function_exists('vehicle_promo_pricing')) {
        require_once __DIR__ . '/vehicle_promo.php';
    }
    $cache = [];
    $sql = "
        SELECT v.id, v.make_model, v.vehicle_type, v.seats, v.transmission, v.plate_no, v.photo,
               v.daily_rate, v.daily_rate_cdo, v.daily_rate_outside_cdo,
               v.promo_discount_type, v.promo_discount_value, v.promo_starts_at, v.promo_ends_at,
               (
                 SELECT r.end_date
                 FROM rentals r
                 WHERE r.vehicle_id = v.id
                   AND r.status IN ('ongoing','reserved','approved','pending')
                   AND r.start_date <= CURDATE()
                   AND r.end_date >= CURDATE()
                 ORDER BY r.end_date DESC
                 LIMIT 1
               ) AS busy_until
        FROM vehicles v
        WHERE v.current_status IS NULL
           OR LOWER(v.current_status) NOT IN ('maintenance','scheduled_maintenance','inspection','unavailable')
        ORDER BY v.vehicle_type, v.make_model
    ";
    try {
        $res = $conn->query($sql);
    } catch (Throwable $e) {
        $res = false;
    }
    if (!$res) {
        return $cache;
    }
    while ($row = $res->fetch_assoc()) {
        $promo = function_exists('vehicle_promo_pricing') ? vehicle_promo_pricing($row) : ['has_promo' => false, 'effective' => (float)($row['daily_rate_cdo'] ?? 0), 'base' => (float)($row['daily_rate_cdo'] ?? 0), 'label' => ''];
        $row['has_promo'] = !empty($promo['has_promo']);
        $row['promo_label'] = (string)($promo['label'] ?? '');
        $row['price_now'] = round((float)($promo['effective'] ?? 0), 2);
        $row['price_was'] = round((float)($promo['base'] ?? 0), 2);
        $cache[] = $row;
    }
    return $cache;
}

function gemini_fleet_availability(mysqli $conn, bool $includePlate = false): array {
    $lists = ['available_now' => [], 'busy_today' => [], 'discounted_now' => []];
    foreach (gemini_public_catalog($conn) as $row) {
        $card = gemini_vehicle_card($row, $includePlate);
        if (!empty($row['has_promo'])) {
            $lists['discounted_now'][] = $card;
        }
        $busyUntil = trim((string)($row['busy_until'] ?? ''));
        if ($busyUntil !== '') {
            $card['free_after'] = $busyUntil;
            $lists['busy_today'][] = $card;
        } else {
            $lists['available_now'][] = $card;
        }
    }
    return $lists;
}

function gemini_cars_mentioned(string $text, array $catalog): array {
    $hay = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    $rows = $catalog;
    usort($rows, function ($a, $b) {
        return strlen((string)($b['make_model'] ?? '')) <=> strlen((string)($a['make_model'] ?? ''));
    });
    $cars = [];
    $seen = [];
    foreach ($rows as $row) {
        $name = trim((string)($row['make_model'] ?? ''));
        $id = (int)($row['id'] ?? 0);
        if ($name === '' || $id <= 0 || isset($seen[$id])) {
            continue;
        }
        $needle = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
        if (!str_contains($hay, $needle)) {
            continue;
        }
        $seen[$id] = true;
        $priceNow = round((float)($row['price_now'] ?? $row['daily_rate_cdo'] ?? 0), 2);
        $priceWas = round((float)($row['price_was'] ?? $priceNow), 2);
        $cars[] = [
            'id' => $id,
            'name' => $name,
            'photo' => gemini_vehicle_photo($row['photo'] ?? ''),
            'price' => '₱' . number_format($priceNow, 2) . '/day',
            'was' => (!empty($row['has_promo']) && $priceWas > $priceNow) ? ('₱' . number_format($priceWas, 2) . '/day') : '',
            'discount' => (string)($row['promo_label'] ?? ''),
            'href' => 'vehiclepage.php?book=' . $id,
        ];
    }
    return $cars;
}

function gemini_customer_context(mysqli $conn, int $userId): array {
    $fleet = gemini_fleet_availability($conn, false);
    $ctx = [
        'company' => 'FleetGo',
        'currency' => 'PHP',
        'today' => date('Y-m-d'),
        'office' => 'Gingoog City, Barangay Test Only',
        'how_to_read' => 'available_now vehicles can be booked today from Browse Cars. busy_today vehicles are already booked through free_after. discounted_now is the only list of cars with a discount active right now. When you name a car, copy its name exactly. A stored status of rented is not enough to call a vehicle unavailable.',
        'policies' => gemini_policy_brief(),
        'available_now' => $fleet['available_now'],
        'busy_today' => $fleet['busy_today'],
        'discounted_now' => $fleet['discounted_now'],
        'my_rentals' => [],
    ];

    $stmt = $conn->prepare("
        SELECT v.make_model, r.start_date, r.end_date, r.status, r.total_cost, r.balance_due
        FROM rentals r
        JOIN vehicles v ON v.id = r.vehicle_id
        WHERE r.customer_id = ?
        ORDER BY r.id DESC
        LIMIT 8
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $mine = $stmt->get_result();
    while ($row = $mine->fetch_assoc()) {
        $ctx['my_rentals'][] = [
            'vehicle' => (string)$row['make_model'],
            'start' => (string)$row['start_date'],
            'end' => (string)$row['end_date'],
            'status' => (string)$row['status'],
            'total' => round((float)$row['total_cost'], 2),
            'balance_due' => round((float)$row['balance_due'], 2),
        ];
    }
    $stmt->close();

    return $ctx;
}
