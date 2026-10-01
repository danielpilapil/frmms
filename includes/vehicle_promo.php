<?php
/* ============================================================
   FleetGo — Vehicle promo pricing helpers (browse + booking)
============================================================ */

if (!function_exists('vehicle_promo_ensure_columns')) {
  function vehicle_promo_ensure_columns(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
      $cols = [
        'promo_discount_type' => "ADD COLUMN promo_discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none'",
        'promo_discount_value' => "ADD COLUMN promo_discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'promo_starts_at' => "ADD COLUMN promo_starts_at DATETIME NULL DEFAULT NULL",
        'promo_ends_at' => "ADD COLUMN promo_ends_at DATETIME NULL DEFAULT NULL",
      ];
      foreach ($cols as $name => $ddl) {
        $chk = $conn->query("SHOW COLUMNS FROM vehicles LIKE '" . $conn->real_escape_string($name) . "'");
        if (!$chk || $chk->num_rows === 0) {
          $conn->query("ALTER TABLE vehicles " . $ddl);
        }
      }
    } catch (Throwable $e) { /* ignore */ }
  }
}

if (!function_exists('vehicle_promo_is_active_now')) {
  /**
   * Promo is active when:
   * - type/value are set, AND
   * - either no schedule is set (always on), OR now is within [starts_at, ends_at]
   */
  function vehicle_promo_is_active_now(?string $startsAt, ?string $endsAt): bool {
    $startsAt = trim((string)$startsAt);
    $endsAt = trim((string)$endsAt);
    if ($startsAt === '' && $endsAt === '') {
      return true; // no window configured → treat as always active
    }
    $now = time();
    $startTs = $startsAt !== '' ? strtotime($startsAt) : false;
    $endTs = $endsAt !== '' ? strtotime($endsAt) : false;
    // If end time is HH:MM:00, treat as inclusive through that minute.
    if ($endTs && preg_match('/:\d{2}:00$/', $endsAt)) {
      $endTs += 59;
    }
    if ($startTs && $endTs) {
      return ($now >= $startTs && $now <= $endTs);
    }
    if ($startTs && !$endTs) {
      return $now >= $startTs;
    }
    if ($endTs && !$startTs) {
      return $now <= $endTs;
    }
    return false;
  }
}

if (!function_exists('vehicle_promo_pricing')) {
  /**
   * @return array{base:float,effective:float,has_promo:bool,type:string,value:float,label:string,save:float,active:bool,starts_at:?string,ends_at:?string}
   */
  function vehicle_promo_pricing(array $row): array {
    $base = (float)($row['daily_rate_cdo'] ?? $row['daily_rate'] ?? 0);
    if ($base <= 0) $base = (float)($row['daily_rate'] ?? 0);
    $type = strtolower(trim((string)($row['promo_discount_type'] ?? 'none')));
    $value = (float)($row['promo_discount_value'] ?? 0);
    $startsAt = trim((string)($row['promo_starts_at'] ?? ''));
    $endsAt = trim((string)($row['promo_ends_at'] ?? ''));
    $out = [
      'base' => $base,
      'effective' => $base,
      'has_promo' => false,
      'type' => 'none',
      'value' => 0.0,
      'label' => '',
      'save' => 0.0,
      'active' => false,
      'starts_at' => $startsAt !== '' ? $startsAt : null,
      'ends_at' => $endsAt !== '' ? $endsAt : null,
    ];
    if ($value <= 0 || !in_array($type, ['percent', 'fixed'], true) || $base <= 0) {
      return $out;
    }
    if (!vehicle_promo_is_active_now($startsAt, $endsAt)) {
      return $out;
    }

    if ($type === 'percent') {
      $pct = min(100.0, max(0.0, $value));
      $save = $base * ($pct / 100.0);
      $label = rtrim(rtrim(number_format($pct, 2), '0'), '.') . '% OFF';
    } else {
      $save = min($base, max(0.0, $value));
      $label = '₱' . number_format($save, 0) . ' OFF / day';
    }
    $effective = max(0.0, $base - $save);
    if ($save <= 0.00001) return $out;

    return [
      'base' => $base,
      'effective' => $effective,
      'has_promo' => true,
      'type' => $type,
      'value' => $value,
      'label' => $label,
      'save' => $save,
      'active' => true,
      'starts_at' => $startsAt !== '' ? $startsAt : null,
      'ends_at' => $endsAt !== '' ? $endsAt : null,
    ];
  }
}

if (!function_exists('render_vehicle_price_html')) {
  function render_vehicle_price_html(array $row): string {
    $esc = function_exists('h')
      ? 'h'
      : function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $p = vehicle_promo_pricing($row);
    $id = (int)($row['id'] ?? 0);
    if (!$p['has_promo']) {
      return '<div class="v-price" data-vehicle-price="' . $id . '">'
        . '₱' . number_format($p['base'], 2) . ' <span>/ day</span></div>';
    }
    return '<div class="v-price has-promo" data-vehicle-price="' . $id . '">'
      . '<span class="promo-pill">' . $esc($p['label']) . '</span>'
      . '<div class="price-now">₱' . number_format($p['effective'], 2) . ' <span>/ day</span></div>'
      . '<div class="price-was">₱' . number_format($p['base'], 2) . '/day</div>'
      . '</div>';
  }
}

if (!function_exists('attach_promo_fields_to_vehicle')) {
  function attach_promo_fields_to_vehicle(array $row): array {
    $p = vehicle_promo_pricing($row);
    $row['promo_pricing'] = $p;
    $row['display_daily_rate'] = $p['effective'];
    return $row;
  }
}
