<?php
/**
 * Gate 5: placeholder resolution matrix.
 * Extracts {{placeholders}} from every template (body + subject) in both
 * seeders and checks them against the variable maps the real senders produce,
 * extracted directly from the service sources. Dormant templates are checked
 * against the union of every sender map.
 */
$root = __DIR__.'/../../../../';
require $root.'/vendor/autoload.php';

function extractTemplates(string $path, string $class): array {
    require_once $path;
    $rc = new ReflectionClass($class);
    $const = $rc->getReflectionConstant('TEMPLATES');
    if ($const === false) { fwrite(STDERR, "no TEMPLATES in $class\n"); exit(2); }
    return $const->getValue();
}

function mapKeysFromSource(string $path, string $methodSig): array {
    $src = file_get_contents($path);
    $pos = strpos($src, $methodSig);
    if ($pos === false) { fwrite(STDERR, "sig not found: $methodSig\n"); exit(2); }
    $start = $pos;
    $end = strpos($src, 'function ', $start + strlen($methodSig));
    if ($end === false) { $end = strlen($src); }
    $window = substr($src, $start, $end - $start);
    preg_match_all("/'([a-zA-Z_][a-zA-Z0-9_]*)'\s*=>/", $window, $m);
    return array_values(array_unique($m[1]));
}

$seeder = extractTemplates($root.'/database/seeders/EmailTemplateSeeder.php', Database\Seeders\EmailTemplateSeeder::class);
$demo   = extractTemplates($root.'/database/seeders/Demo/NotificationEmailSeeder.php', Database\Seeders\Demo\NotificationEmailSeeder::class);

$branding = mapKeysFromSource($root.'/app/Services/Concerns/BuildsEmailVariables.php', 'protected function brandingVariables');
$order    = array_merge($branding, mapKeysFromSource($root.'/app/Services/OrderEmailService.php', 'public function buildVariables(Order'));
$invoice  = array_merge($branding, mapKeysFromSource($root.'/app/Services/InvoiceEmailService.php', 'public function buildVariables(Invoice'));
$chat     = array_merge($branding, mapKeysFromSource($root.'/app/Services/ChatTranscriptEmailService.php', 'public function buildVariables(ChatConversation'));
$welcome  = mapKeysFromSource($root.'/app/Services/Provisioning/WelcomeMailer.php', 'private function variables(Order');
$ticket   = mapKeysFromSource($root.'/app/Services/TicketMailService.php', 'private function templateVariables(Ticket');

$union = array_values(array_unique(array_merge($branding, $order, $invoice, $chat, $welcome, $ticket)));

$senderFor = [
  'welcome' => 'UNION(dormant)', 'password_reset' => 'UNION(dormant)',
  'order_confirmation' => 'order', 'invoice_created' => 'invoice',
  'invoice_overdue_reminder' => 'invoice', 'payment_received' => 'invoice',
  'service_activated' => 'welcome', 'service_suspended' => 'UNION(dormant)',
  'service_cancelled' => 'UNION(dormant)', 'support_ticket_opened' => 'ticket',
  'support_ticket_reply' => 'ticket', 'chat_transcript' => 'chat',
];

$failures = 0;
$report = [];
foreach (['canonical'=>$seeder, 'demo'=>$demo] as $group => $templates) {
    foreach ($templates as $t) {
        $map = $senderFor[$t['name']] ?? 'UNION(dormant)';
        $vocab = match ($map) { 'order'=>$order, 'invoice'=>$invoice, 'chat'=>$chat, 'welcome'=>$welcome, 'ticket'=>$ticket, default=>$union };
        $text = $t['subject']."\n".$t['body'];
        preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $text, $m);
        $used = array_unique($m[1]);
        $missing = array_values(array_diff($used, $vocab));
        $report[] = sprintf("%-7s %-26s used=%-3d missing=[%s]", $group, $t['name'], count($used), implode(', ', $missing));
        if ($missing !== []) { $failures++; }
    }
}
echo implode("\n", $report)."\n";
echo "branding keys: ".implode(',', $branding)."\n";
echo "ticket keys: ".implode(',', $ticket)."\n";
echo "welcome keys: ".implode(',', $welcome)."\n";
echo "UNRESOLVED template-groups: $failures\n";
exit($failures === 0 ? 0 : 1);
