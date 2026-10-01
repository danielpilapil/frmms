<?php
/* FleetGo — Admin-managed payment methods (PH banks & e-wallets) */

if (!function_exists('pm_providers')) {
  /** Codes are stored in rental_payments.payment_method (VARCHAR(20)). */
  function pm_providers(): array {
    return [
      'ewallet' => [
        'GCASH'      => 'GCash',
        'MAYA'       => 'Maya',
        'GRABPAY'    => 'GrabPay',
        'SHOPEEPAY'  => 'ShopeePay',
        'COINSPH'    => 'Coins.ph',
        'PALAWANPAY' => 'PalawanPay',
        'GOTYME'     => 'GoTyme',
      ],
      'bank' => [
        'BDO'        => 'BDO Unibank',
        'BPI'        => 'Bank of the Philippine Islands (BPI)',
        'METROBANK'  => 'Metrobank',
        'LANDBANK'   => 'Land Bank of the Philippines',
        'PNB'        => 'Philippine National Bank (PNB)',
        'SECBANK'    => 'Security Bank',
        'UNIONBANK'  => 'UnionBank',
        'CHINABANK'  => 'China Bank',
        'RCBC'       => 'RCBC',
        'EASTWEST'   => 'EastWest Bank',
        'PSBANK'     => 'PSBank',
        'AUB'        => 'Asia United Bank (AUB)',
        'DBP'        => 'Development Bank of the Philippines (DBP)',
        'MAYBANK'    => 'Maybank Philippines',
        'BANKCOM'    => 'Bank of Commerce',
        'PBCOM'      => 'PBCOM',
        'CIMB'       => 'CIMB Bank Philippines',
        'SEABANK'    => 'SeaBank',
        'TONIK'      => 'Tonik Digital Bank',
        'UNOBANK'    => 'UNO Digital Bank',
        'MAYABANK'   => 'Maya Bank',
      ],
    ];
  }
}

if (!function_exists('pm_provider_lookup')) {
  /** @return array{type:string,name:string}|null */
  function pm_provider_lookup(string $code): ?array {
    $code = strtoupper(trim($code));
    foreach (pm_providers() as $type => $list) {
      if (isset($list[$code])) return ['type' => $type, 'name' => $list[$code]];
    }
    return null;
  }
}

if (!function_exists('pm_ensure_table')) {
  function pm_ensure_table(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->query("
      CREATE TABLE IF NOT EXISTS admin_payment_methods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        provider_code VARCHAR(20) NOT NULL,
        provider_name VARCHAR(100) NOT NULL,
        provider_type ENUM('bank','ewallet') NOT NULL,
        account_name VARCHAR(120) NULL,
        account_number VARCHAR(60) NOT NULL,
        qr_image VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active (is_active)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  }
}

if (!function_exists('pm_list')) {
  function pm_list(mysqli $conn, bool $activeOnly = false): array {
    pm_ensure_table($conn);
    $sql = "SELECT id, provider_code, provider_name, provider_type, account_name, account_number, qr_image, is_active
            FROM admin_payment_methods"
         . ($activeOnly ? " WHERE is_active = 1" : "")
         . " ORDER BY provider_type = 'ewallet' DESC, provider_name ASC, id ASC";
    $rows = [];
    if ($res = $conn->query($sql)) {
      while ($r = $res->fetch_assoc()) {
        $r['id'] = (int)$r['id'];
        $r['is_active'] = (int)$r['is_active'];
        $rows[] = $r;
      }
    }
    return $rows;
  }
}

if (!function_exists('pm_get')) {
  function pm_get(mysqli $conn, int $id, bool $activeOnly = true): ?array {
    pm_ensure_table($conn);
    $sql = "SELECT id, provider_code, provider_name, provider_type, account_name, account_number, qr_image, is_active
            FROM admin_payment_methods WHERE id = ?" . ($activeOnly ? " AND is_active = 1" : "") . " LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
  }
}
