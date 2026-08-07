<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'product_group_id', 'description', 'price', 'billing_cycle', 'setup_fee', 'provisioning_module', 'server_group_id', 'welcome_email_template_id', 'require_domain', 'show_in_order', 'show_in_affiliate', 'only_admin', 'sort_order', 'quota_disk', 'quota_bandwidth', 'quota_email', 'quota_database', 'quota_cpu_cores', 'quota_cpu_speed', 'quota_ram', 'quota_ips', 'quota_ftp_accounts', 'quota_subdomains', 'status', 'gst_enabled', 'gst_rate', 'gst_type', 'cgst_rate', 'sgst_rate', 'igst_rate'])]
class Product extends Model
{
    protected $casts = [
        'price' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'cgst_rate' => 'decimal:2',
        'sgst_rate' => 'decimal:2',
        'igst_rate' => 'decimal:2',
        'require_domain' => 'boolean',
        'show_in_order' => 'boolean',
        'show_in_affiliate' => 'boolean',
        'only_admin' => 'boolean',
        'gst_enabled' => 'boolean',
    ];

    /**
     * Product types (reference: modules/products/ProductModel::TYPES).
     */
    public const TYPES = [
        'shared_hosting' => 'Shared Hosting',
        'reseller' => 'Reseller Hosting',
        'vps' => 'VPS',
        'dedicated' => 'Dedicated Server',
        'domain' => 'Domain',
        'addon' => 'Add-on',
        'bundle' => 'Bundle',
        'hosting' => 'Hosting (Legacy)',
        'other' => 'Other',
    ];

    /**
     * Provisioning modules (reference: ProductModel::PROVISIONING_MODULES).
     */
    public const PROVISIONING_MODULES = [
        'none' => 'None',
        'cpanel' => 'cPanel/WHM',
        'plesk' => 'Plesk',
        'directadmin' => 'DirectAdmin',
        'virtualizor' => 'Virtualizor',
        'custom' => 'Custom',
    ];

    /**
     * Billing cycles valid on `product_pricing` rows (8 values).
     */
    public const BILLING_CYCLES = [
        'free' => 'Free',
        'one_time' => 'One Time',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'semi_annual' => 'Semi-Annual',
        'annual' => 'Annual',
        'biennial' => 'Biennial',
        'triennial' => 'Triennial',
    ];

    /**
     * Billing cycles valid on the `products.billing_cycle` column (the
     * product's default cycle). Narrower than BILLING_CYCLES: no free /
     * triennial on the main column.
     */
    public const DEFAULT_CYCLES = [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'semi_annual' => 'Semi-Annual',
        'annual' => 'Annual',
        'biennial' => 'Biennial',
        'one_time' => 'One Time',
    ];

    /**
     * GST types (reference: ProductModel::GST_TYPES).
     */
    public const GST_TYPES = [
        'standard' => 'Standard (18%)',
        'exempt' => 'Exempt (0%)',
        'reverse_charge' => 'Reverse Charge',
    ];

    /**
     * Whether provisioning this product requires leasing an IP address.
     * Single source of truth for the "needs an IP" rule.
     */
    public function requiresIp(): bool
    {
        return in_array($this->type, ['vps', 'dedicated'], true);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function pricing(): HasMany
    {
        return $this->hasMany(ProductPricing::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOptionGroup::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(ProductAddon::class);
    }

    public function meta(): HasMany
    {
        return $this->hasMany(ProductMeta::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function hostingAccounts(): HasMany
    {
        return $this->hasMany(HostingAccount::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
