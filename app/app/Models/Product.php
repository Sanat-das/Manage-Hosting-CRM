<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'product_group_id', 'description', 'price', 'billing_cycle', 'payment_type', 'setup_fee', 'provisioning_module', 'server_group_id', 'welcome_email_template_id', 'require_domain', 'quantity_behaviour', 'recurring_cycles_limit', 'auto_terminate_value', 'auto_terminate_unit', 'prorata_enabled', 'prorata_date', 'prorata_charge_next_month', 'early_renewal_mode', 'early_renewal_days', 'require_public_ip', 'require_private_ip', 'show_in_order', 'show_in_affiliate', 'only_admin', 'sort_order', 'status', 'is_bundle', 'gst_enabled', 'gst_rate', 'gst_type', 'cgst_rate', 'sgst_rate', 'igst_rate'])]
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
        'quantity_behaviour' => 'string',
        'recurring_cycles_limit' => 'integer',
        'auto_terminate_value' => 'integer',
        'auto_terminate_unit' => 'string',
        'prorata_enabled' => 'boolean',
        'prorata_date' => 'integer',
        'prorata_charge_next_month' => 'boolean',
        'early_renewal_mode' => 'string',
        'early_renewal_days' => 'array',
        'require_public_ip' => 'boolean',
        'require_private_ip' => 'boolean',
        'show_in_order' => 'boolean',
        'show_in_affiliate' => 'boolean',
        'only_admin' => 'boolean',
        'is_bundle' => 'boolean',
        'gst_enabled' => 'boolean',
    ];

    /**
     * Provisioning modules (reference: ProductModel::PROVISIONING_MODULES).
     *
     * 'manual' is the default: after payment the order awaits manual
     * provisioning. The automated modules auto-provision instead.
     */
    public const PROVISIONING_MODULES = [
        'manual' => 'Manual',
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
     * Whether the product is sold as a single unit (no quantity selector).
     * quantity_behaviour is the source of truth: 'none' = single unit.
     */
    public function isSingleUnit(): bool
    {
        return ($this->quantity_behaviour ?? 'multiple_services') === 'none';
    }

    /**
     * Whether provisioning this product requires leasing an IP address.
     *
     * Flag-driven: the requirement is declared per-product on the product
     * edit page (require_public_ip / require_private_ip) instead of being
     * hardcoded by product type. Any product type can require IPs now.
     */
    public function requiresIp(): bool
    {
        return $this->require_public_ip || $this->require_private_ip;
    }

    /**
     * Whether provisioning this product requires a public IP (lease of a
     * 'public' network_type subnet address at activation).
     */
    public function requiresPublicIp(): bool
    {
        return (bool) $this->require_public_ip;
    }

    /**
     * Whether provisioning this product requires a private IP (lease of a
     * 'private' network_type subnet address at activation).
     */
    public function requiresPrivateIp(): bool
    {
        return (bool) $this->require_private_ip;
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function pricing(): HasMany
    {
        return $this->hasMany(ProductPricing::class);
    }

    /**
     * Component rows when this product is a bundle (products.is_bundle flag).
     */
    public function bundleChildren(): HasMany
    {
        return $this->hasMany(ProductBundle::class, 'bundle_product_id');
    }

    /**
     * Whether this product is a bundle. Flag-driven (products.is_bundle) —
     * bundle products carry no price of their own; pricing is derived from
     * component ProductPricing rows via ProductBundlePricingService.
     */
    public function isBundle(): bool
    {
        return (bool) $this->is_bundle;
    }

    /**
     * All upgrade paths out of this product (regardless of enabled state).
     */
    public function upgradePaths(): HasMany
    {
        return $this->hasMany(ProductUpgradePath::class, 'from_product_id');
    }

    /**
     * Upgrade paths out of this product that are currently enabled.
     */
    public function upgradeableTo(): HasMany
    {
        return $this->upgradePaths()->where('enabled', true);
    }

    /**
     * Option groups attached to this product via the
     * `product_option_group_product` pivot (many-to-many).
     */
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductOptionGroup::class,
            'product_option_group_product',
            'product_id',
            'option_group_id'
        )->withPivot(['customer_editable'])->withTimestamps();
    }

    /**
     * Pivot rows linking this product to option groups (one per group),
     * in display order (sort_order, then pivot id for stable ties).
     */
    public function optionLinks(): HasMany
    {
        return $this->hasMany(ProductOptionGroupProduct::class, 'product_id')
            ->orderBy('sort_order')
            ->orderBy('id');
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
