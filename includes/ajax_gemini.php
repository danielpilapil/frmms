<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}
$action = (string)($payload['action'] ?? '');

$adminActions = ['report_summary', 'admin_chat', 'vehicle_description'];
$wantAdmin = in_array($action, $adminActions, true);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
if ($wantAdmin) {
    session_name(isset($_COOKIE['fleetgo_session_admin']) ? 'fleetgo_session_admin' : 'fleetgo_session_guest');
} else {
    session_name(isset($_COOKIE['fleetgo_session_user']) ? 'fleetgo_session_user' : 'fleetgo_session_guest');
}
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gemini.php';

function gemini_json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function gemini_require_admin(): void {
    if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
        gemini_json(['ok' => false, 'error' => 'Admin login required.'], 403);
    }
}

function gemini_require_user(): int {
    if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'user')) {
        gemini_json(['ok' => false, 'error' => 'Please log in to use the assistant.'], 403);
    }
    return (int)$_SESSION['user_id'];
}

function gemini_history(array $payload): array {
    $history = $payload['history'] ?? [];
    return is_array($history) ? $history : [];
}

try {
    if ($action === 'report_summary') {
        gemini_require_admin();
        $from = trim((string)($payload['from'] ?? ''));
        $to = trim((string)($payload['to'] ?? ''));
        $vehicleId = (int)($payload['vehicle_id'] ?? 0);
        $tab = preg_replace('/[^a-z_]/', '', strtolower((string)($payload['tab'] ?? 'earnings'))) ?? 'earnings';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            gemini_json(['ok' => false, 'error' => 'Choose a valid report date range first.'], 400);
        }
        $ctx = gemini_report_context($conn, $from, $to, $vehicleId, $tab);
        $system = 'You write a short operations summary for FleetGo, a car rental company in Cagayan de Oro. Use only the JSON numbers provided. Currency is Philippine pesos. Write 2 to 4 short paragraphs in plain text. Do not invent figures, vehicles, or customers. If a list is empty, say so.';
        $user = "Summarize this {$tab} report.\n\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE);
        $result = gemini_generate($system, gemini_contents_from_history([], $user), 0.3, 600);
        gemini_json($result, $result['ok'] ? 200 : 502);
    }

    if ($action === 'admin_chat') {
        gemini_require_admin();
        $question = gemini_clip((string)($payload['message'] ?? ''), 2000);
        if ($question === '') {
            gemini_json(['ok' => false, 'error' => 'Type a question first.'], 400);
        }
        $ctx = gemini_admin_context($conn);
        $system = "You are the FleetGo admin assistant in Cagayan de Oro. Answer only from the fleet snapshot. Lead with the direct answer, then the specific vehicles, rentals, or amounts that support it, then one practical next step. available_now means the vehicle can be booked today. busy_today means it is booked through free_after. Never call an available_now vehicle unavailable. Plain sentences only, no markdown or asterisks. Currency is Philippine pesos. If the snapshot does not contain the answer, say so.\n\nFleet snapshot:\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE);
        $result = gemini_generate($system, gemini_contents_from_history(gemini_history($payload), $question), 0.2, 700);
        gemini_json($result, $result['ok'] ? 200 : 502);
    }

    if ($action === 'customer_chat') {
        $userId = gemini_require_user();
        $question = gemini_clip((string)($payload['message'] ?? ''), 2000);
        if ($question === '') {
            gemini_json(['ok' => false, 'error' => 'Type a question first.'], 400);
        }
        $ctx = gemini_customer_context($conn, $userId);
        $system = "You are the FleetGo booking assistant. The office is in Gingoog City, Barangay Test Only. Help the customer choose a vehicle, book it, and understand the late fee and discounts. Use only the data below. Never mention other customers. Do not invent vehicles, prices, discounts, or addresses.\nRules: Copy each vehicle name exactly when you mention it. discounted_now is the complete list of cars with a discount active right now; if it is empty, say there are no discounted cars right now. For available cars, name transmission, seats, and the Cagayan de Oro daily rate, and mention the discount when price_now is present. A busy_today car can still be booked for dates after free_after. Booking steps: open Browse Cars, choose the car, tap Book Now, set the dates, and accept the terms. A new booking is waitlist until the customer pays and submits a receipt, then it becomes pending for admin approval. Late fee is (daily rate divided by 24) times hours late times 1.25. Plain sentences only. No markdown, bullets, or asterisks.\n\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE);
        $result = gemini_generate($system, gemini_contents_from_history(gemini_history($payload), $question), 0.2, 700);
        if (!empty($result['ok'])) {
            $result['cars'] = gemini_cars_mentioned($result['text'], gemini_public_catalog($conn));
        }
        gemini_json($result, $result['ok'] ? 200 : 502);
    }

    if ($action === 'vehicle_description') {
        gemini_require_admin();
        $vehicle = $payload['vehicle'] ?? [];
        if (!is_array($vehicle)) {
            $vehicle = [];
        }
        $facts = [
            'maker' => gemini_clip((string)($vehicle['maker'] ?? ''), 80),
            'model' => gemini_clip((string)($vehicle['model'] ?? ''), 80),
            'type' => gemini_clip((string)($vehicle['type'] ?? ''), 40),
            'year' => gemini_clip((string)($vehicle['year'] ?? ''), 8),
            'seats' => gemini_clip((string)($vehicle['seats'] ?? ''), 8),
            'transmission' => gemini_clip((string)($vehicle['transmission'] ?? ''), 20),
            'comfort' => gemini_clip((string)($vehicle['comfort'] ?? ''), 40),
            'fuel' => gemini_clip((string)($vehicle['fuel'] ?? ''), 40),
            'category' => gemini_clip((string)($vehicle['category'] ?? ''), 40),
            'condition' => gemini_clip((string)($vehicle['condition'] ?? ''), 40),
            'daily_rate_cdo' => gemini_clip((string)($vehicle['daily_rate_cdo'] ?? ''), 20),
            'daily_rate_outside_cdo' => gemini_clip((string)($vehicle['daily_rate_outside_cdo'] ?? ''), 20),
        ];
        if ($facts['maker'] === '' || $facts['model'] === '') {
            gemini_json(['ok' => false, 'error' => 'Enter the maker and model first.'], 400);
        }
        $system = 'Write a customer-facing listing description of 2 or 3 sentences for a FleetGo rental vehicle in Cagayan de Oro. Use only the supplied facts. Do not invent features, safety claims, mileage, or discounts. Plain text only, no markdown or title.';
        $user = json_encode($facts, JSON_UNESCAPED_UNICODE);
        $result = gemini_generate($system, gemini_contents_from_history([], $user), 0.5, 220);
        gemini_json($result, $result['ok'] ? 200 : 502);
    }

    gemini_json(['ok' => false, 'error' => 'Unknown request.'], 400);
} catch (Throwable $e) {
    error_log('ajax_gemini: ' . $e->getMessage());
    gemini_json(['ok' => false, 'error' => 'Something went wrong while talking to Gemini.'], 500);
}
