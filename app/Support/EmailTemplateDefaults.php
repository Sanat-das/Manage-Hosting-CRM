<?php

namespace App\Support;

use Database\Seeders\EmailTemplateSeeder;

/**
 * Canonical defaults + variable taxonomy for email templates.
 * Single source for reset, preview and the editor's variable palette.
 */
final class EmailTemplateDefaults
{
    /**
     * @return array<string, array{subject:string, body:string, status:string}>
     */
    public static function all(): array
    {
        $ref = new \ReflectionClass(EmailTemplateSeeder::class);
        $prop = $ref->getReflectionConstant('TEMPLATES');
        // ReflectionConstant is PHP 8.1+, fallback to getConstant
        $templates = $ref->getConstant('TEMPLATES');
        $map = [];
        foreach ($templates as $t) {
            $map[$t['name']] = ['subject' => $t['subject'], 'body' => $t['body'], 'status' => $t['status']];
        }
        return $map;
    }

    public static function get(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    /**
     * Grouped variable palette — used by the editor to render insertable chips.
     * Keys match InvoiceEmailService::buildVariables() + legacy account vars.
     *
     * @return array<string, list<array{key:string,label:string,desc:string}>>
     */
    public static function variableGroups(): array
    {
        return [
            'Branding' => [
                ['key' => 'app_name', 'label' => 'App Name', 'desc' => 'Branding::appName()'],
                ['key' => 'app_url', 'label' => 'App URL', 'desc' => 'config(app.url)'],
                ['key' => 'app_logo_url', 'label' => 'Logo URL', 'desc' => 'Brand logo'],
                ['key' => 'tagline', 'label' => 'Tagline', 'desc' => 'Branding tagline'],
                ['key' => 'primary_color', 'label' => 'Primary Color', 'desc' => '#0EA5E9'],
                ['key' => 'footer_text', 'label' => 'Footer Text', 'desc' => '© year'],
            ],
            'Company' => [
                ['key' => 'company_name', 'label' => 'Company Name', 'desc' => 'GeneralSettings'],
                ['key' => 'company_address', 'label' => 'Company Address', 'desc' => 'Single line'],
                ['key' => 'company_email', 'label' => 'Company Email', 'desc' => 'Support email'],
                ['key' => 'company_phone', 'label' => 'Company Phone', 'desc' => 'Support phone'],
            ],
            'Customer' => [
                ['key' => 'name', 'label' => 'Customer Name', 'desc' => 'Alias for customer_name'],
                ['key' => 'customer_name', 'label' => 'Customer Name', 'desc' => 'Full name'],
                ['key' => 'customer_email', 'label' => 'Customer Email', 'desc' => 'Login email'],
                ['key' => 'customer_company', 'label' => 'Customer Company', 'desc' => 'Company field'],
                ['key' => 'customer_id', 'label' => 'Customer ID', 'desc' => '#CLT-00001'],
            ],
            'Invoice' => [
                ['key' => 'invoice_no', 'label' => 'Invoice No', 'desc' => 'INV-2026-00001'],
                ['key' => 'invoice_number', 'label' => 'Invoice Number', 'desc' => 'Alias'],
                ['key' => 'invoice_date', 'label' => 'Invoice Date', 'desc' => 'M j, Y'],
                ['key' => 'due_date', 'label' => 'Due Date', 'desc' => 'M j, Y'],
                ['key' => 'status_label', 'label' => 'Status', 'desc' => 'Paid/Sent...'],
                ['key' => 'total', 'label' => 'Total', 'desc' => '6,240.00'],
                ['key' => 'subtotal', 'label' => 'Subtotal', 'desc' => 'Amount w/o tax'],
                ['key' => 'tax', 'label' => 'Tax', 'desc' => 'Tax amount'],
                ['key' => 'discount', 'label' => 'Discount', 'desc' => 'Discount'],
                ['key' => 'balance', 'label' => 'Balance Due', 'desc' => 'Total - paid'],
                ['key' => 'paid_amount', 'label' => 'Paid Amount', 'desc' => 'Paid so far'],
                ['key' => 'currency', 'label' => 'Currency Code', 'desc' => 'INR'],
                ['key' => 'currency_symbol', 'label' => 'Currency Symbol', 'desc' => '₹'],
                ['key' => 'total_formatted', 'label' => 'Total Formatted', 'desc' => '₹6,240.00'],
            ],
            'Order & URLs' => [
                ['key' => 'order_number', 'label' => 'Order Number', 'desc' => '#ORD-...'],
                ['key' => 'order_no', 'label' => 'Order No', 'desc' => 'Raw'],
                ['key' => 'invoice_url', 'label' => 'View Invoice URL', 'desc' => 'Client portal'],
                ['key' => 'pay_url', 'label' => 'Pay URL', 'desc' => 'Payment page'],
                ['key' => 'pdf_url', 'label' => 'PDF URL', 'desc' => 'Invoice PDF'],
                ['key' => 'invoices_url', 'label' => 'Invoices URL', 'desc' => 'List'],
                ['key' => 'login_url', 'label' => 'Login URL', 'desc' => '/login'],
            ],
            'Other' => [
                ['key' => 'year', 'label' => 'Year', 'desc' => '2026'],
                ['key' => 'support_email', 'label' => 'Support Email', 'desc' => 'Alias company_email'],
                ['key' => 'product_name', 'label' => 'Product Name', 'desc' => 'Service templates'],
                ['key' => 'domain', 'label' => 'Domain', 'desc' => 'example.com'],
                ['key' => 'ticket_no', 'label' => 'Ticket No', 'desc' => 'TKT-...'],
            ],
        ];
    }

    /** Flat list of keys for validation hints */
    public static function allKeys(): array
    {
        $keys = [];
        foreach (self::variableGroups() as $group) {
            foreach ($group as $v) $keys[] = $v['key'];
        }
        return array_unique($keys);
    }
}
