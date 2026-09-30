<?php
$root = __DIR__.'/../../../../';
require $root.'/vendor/autoload.php';
function extractTemplates(string $path, string $class): array {
    require_once $path;
    $rc = new ReflectionClass($class);
    return $rc->getReflectionConstant('TEMPLATES')->getValue();
}
$seeder = extractTemplates($root.'/database/seeders/EmailTemplateSeeder.php', Database\Seeders\EmailTemplateSeeder::class);
$demo   = extractTemplates($root.'/database/seeders/Demo/NotificationEmailSeeder.php', Database\Seeders\Demo\NotificationEmailSeeder::class);

// Exact copy of BuildsEmailVariables::toPlainText (trait, read at review time)
function toPlainText(string $html): string {
    $text = preg_replace('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', '$2 ($1)', $html) ?? $html;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
}
// Exact copy of WelcomeMailer::toPlainText
function welcomeToPlainText(string $html): string {
    $text = preg_replace('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', '$2 ($1)', $html) ?? $html;
    $text = preg_replace('/<\/(tr|p|div|h[1-6])>/i', "\n", $text) ?? $text;
    $text = preg_replace('/<\/td>/i', ' ', $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    return trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? $text);
}

$vars = ['app_name'=>'Acme Hosting','app_url'=>'https://acme.example.com','app_logo_url'=>'data:image/png;base64,AAAA','logo_url'=>'data:image/png;base64,AAAA','tagline'=>'','primary_color'=>'#2563eb','footer_text'=>'Your trusted hosting partner','company_name'=>'Acme Hosting Ltd','company_email'=>'billing@acme.example.com','company_phone'=>'+1-555-0100','company_address'=>'123 Main St, Springfield','currency'=>'INR','currency_symbol'=>'₹','login_url'=>'https://acme.example.com/login','support_email'=>'billing@acme.example.com','support_url'=>'https://acme.example.com/support','year'=>'2026','current_year'=>'2026','name'=>'Jane Doe','customer_name'=>'Jane Doe','invoice_no'=>'INV-1001','order_number'=>'ORD-1','total'=>'1,234.00','due_date'=>'Oct 31, 2026','status_label'=>'Paid','customer_email'=>'jane@example.com'];

echo "== PLAIN-TEXT derivation of canonical invoice_created (BuildsEmailVariables::toPlainText) ==\n";
foreach ($seeder as $t) if ($t['name']==='invoice_created') $body=$t['body'];
$rendered = str_replace(array_map(fn($k)=>"{{".$k."}}", array_keys($vars)), array_values($vars), $body);
$plain = toPlainText($rendered);
$lines = explode("\n", $plain);
echo "--- full plain text (".strlen($plain)." chars) ---\n$plain\n";
echo "--- footer region only ---\n";
$idx = array_search('Acme Hosting Ltd', $lines);
for ($i = max(0,$idx-1); $i < min(count($lines), $idx+4); $i++) echo "L$i: ".json_encode($lines[$i])."\n";

echo "\n== WelcomeMailer::toPlainText on service_activated, footer region ==\n";
foreach ($seeder as $t) if ($t['name']==='service_activated') $body=$t['body'];
$vars2 = $vars + ['product_name'=>'VPS-1','domain'=>'vps1.example.com','order_number'=>'ORD-1','activation_date'=>'Sep 29, 2026','service_credentials'=>'','support_email'=>'billing@acme.example.com','customer_id'=>'C-1'];
$rendered = str_replace(array_map(fn($k)=>"{{".$k."}}", array_keys($vars2)), array_values($vars2), $body);
$plain = welcomeToPlainText($rendered);
$lines = explode("\n", $plain);
$idx = array_search('Acme Hosting Ltd', $lines);
for ($i = max(0,$idx-1); $i < min(count($lines), $idx+3); $i++) echo "L$i: ".json_encode($lines[$i])."\n";

echo "\n== Risk C: TicketMailService::body() with DEMO support_ticket_reply template ==\n";
foreach ($demo as $t) if ($t['name']==='support_ticket_reply') $demoBody=$t['body'];
$tvars = ['name'=>'Jane Doe','customer_name'=>'Jane Doe','ticket_no'=>'TKT-0001','subject'=>'Cannot log in','department'=>'Sales','status'=>'open','priority'=>'normal','message'=>'Please try again.','app_name'=>'Demo Hosting','app_url'=>'https://demo.example.com','company_name'=>'Demo Hosting','company_email'=>'support@demo.example.com'];
$intro = str_replace(array_map(fn($k)=>"{{".$k."}}", array_keys($tvars)), array_values($tvars), $demoBody);
$out = $intro."\n\nPlease try again.\n\nReply to this email to add to the ticket.\n\n##- Please type your reply above this line -##";
echo "--- outbound body (first 28 lines) ---\n";
$l = explode("\n", $out);
for ($i=0;$i<min(28,count($l));$i++) echo ($i+1).": ".$l[$i]."\n";
