<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('category', ['capacity', 'discrete']);
            $table->string('unit', 50)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('category');
        });

        DB::table('resource_types')->insert([
            ['name' => 'CPU Core', 'slug' => 'cpu_core', 'category' => 'capacity', 'unit' => 'cores', 'description' => 'Processing cores allocated to a service'],
            ['name' => 'CPU Speed', 'slug' => 'cpu_speed', 'category' => 'capacity', 'unit' => 'MHz', 'description' => 'Total CPU speed in megahertz'],
            ['name' => 'RAM', 'slug' => 'ram', 'category' => 'capacity', 'unit' => 'MB', 'description' => 'Random access memory in megabytes'],
            ['name' => 'Storage', 'slug' => 'storage', 'category' => 'capacity', 'unit' => 'MB', 'description' => 'Primary disk storage in megabytes'],
            ['name' => 'Bandwidth', 'slug' => 'bandwidth', 'category' => 'capacity', 'unit' => 'GB', 'description' => 'Monthly data transfer allowance in gigabytes'],
            ['name' => 'Public IPv4', 'slug' => 'public_ipv4', 'category' => 'capacity', 'unit' => 'count', 'description' => 'Public IPv4 addresses'],
            ['name' => 'Public IPv6', 'slug' => 'public_ipv6', 'category' => 'capacity', 'unit' => 'count', 'description' => 'Public IPv6 addresses or /64 blocks'],
            ['name' => 'Backup Storage', 'slug' => 'backup_storage', 'category' => 'capacity', 'unit' => 'MB', 'description' => 'Backup storage space in megabytes'],
            ['name' => 'GPU Memory', 'slug' => 'gpu_memory', 'category' => 'capacity', 'unit' => 'MB', 'description' => 'GPU VRAM in megabytes (GPU instances)'],
            ['name' => 'Email Accounts', 'slug' => 'email_accounts', 'category' => 'discrete', 'unit' => 'count', 'description' => 'Mailbox accounts per hosting plan'],
            ['name' => 'Databases', 'slug' => 'databases', 'category' => 'discrete', 'unit' => 'count', 'description' => 'Database instances (MySQL, PostgreSQL, etc.)'],
            ['name' => 'FTP Accounts', 'slug' => 'ftp_accounts', 'category' => 'discrete', 'unit' => 'count', 'description' => 'FTP/SFTP user accounts'],
            ['name' => 'Subdomains', 'slug' => 'subdomains', 'category' => 'discrete', 'unit' => 'count', 'description' => 'Allowed subdomains per plan'],
            ['name' => 'Domains', 'slug' => 'domains', 'category' => 'discrete', 'unit' => 'count', 'description' => 'Domain names hosted under this plan'],
            ['name' => 'SSL Certificates', 'slug' => 'ssl_certificates', 'category' => 'discrete', 'unit' => 'count', 'description' => 'SSL/TLS certificates included'],
            ['name' => 'Windows License', 'slug' => 'windows_license', 'category' => 'discrete', 'unit' => 'count', 'description' => 'Windows Server licenses for dedicated/VPS'],
            ['name' => 'cPanel License', 'slug' => 'cpanel_license', 'category' => 'discrete', 'unit' => 'count', 'description' => 'cPanel/WHM licenses per server or account'],
            ['name' => 'Dedicated Server Asset', 'slug' => 'dedicated_server_asset', 'category' => 'discrete', 'unit' => 'count', 'description' => 'Physical dedicated server units'],
        ]);

        Schema::create('product_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('resource_type_id')->constrained('resource_types')->restrictOnDelete();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->boolean('is_required')->default(true);
            $table->boolean('is_upgradable')->default(false);
            $table->decimal('min_quantity', 12, 4)->nullable();
            $table->decimal('max_quantity', 12, 4)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['product_id', 'resource_type_id']);
            $table->index('product_id');
            $table->index('resource_type_id');
        });

        Schema::create('resource_pools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('pool_type', ['hypervisor', 'network', 'storage', 'license']);
            $table->unsignedInteger('parent_id')->nullable();
            $table->decimal('total_capacity', 20, 4)->default(0);
            $table->string('unit', 50)->nullable();
            $table->unsignedInteger('server_id')->nullable();
            $table->unsignedInteger('datacenter_id')->nullable();
            $table->enum('status', ['active', 'maintenance', 'retired'])->default('active');
            $table->timestamps();

            $table->index('pool_type');
            $table->index('parent_id');
            $table->index('server_id');
            $table->index('status');
        });

        Schema::create('resource_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('service_id');
            $table->foreignId('resource_type_id')->constrained('resource_types')->restrictOnDelete();
            $table->unsignedInteger('pool_id')->nullable();
            $table->unsignedInteger('inventory_asset_id')->nullable();
            $table->decimal('quantity_allocated', 20, 4)->default(0);
            $table->dateTime('allocated_at');
            $table->dateTime('released_at')->nullable();
            $table->enum('status', ['allocated', 'released'])->default('allocated');

            $table->index('service_id');
            $table->index(['resource_type_id', 'status']);
        });

        Schema::create('product_quota_summary', function (Blueprint $table) {
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->json('summary_json');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('provisioning_adapters', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('adapter_class');
            $table->enum('method', ['manual', 'cpanel', 'plesk', 'directadmin', 'proxmox', 'vmware', 'hyperv', 'solusvm', 'virtualizor', 'docker', 'kubernetes', 'api', 'custom_script']);
            $table->json('config_schema')->nullable();
            $table->string('api_endpoint_template')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index('method');
            $table->index('is_enabled');
        });

        DB::table('provisioning_adapters')->insert([
            ['name' => 'cpanel', 'adapter_class' => 'Integrations\\CPanel', 'method' => 'cpanel', 'api_endpoint_template' => 'https://{host}:2087/whm/json-api/cpanel', 'is_enabled' => 1],
            ['name' => 'plesk', 'adapter_class' => 'Integrations\\Plesk', 'method' => 'plesk', 'api_endpoint_template' => 'https://{host}:8443/enterprise/control/agent.php', 'is_enabled' => 1],
            ['name' => 'directadmin', 'adapter_class' => 'Integrations\\DirectAdmin', 'method' => 'directadmin', 'api_endpoint_template' => 'https://{host}:2222/CMD_API', 'is_enabled' => 1],
            ['name' => 'virtualizor', 'adapter_class' => 'Integrations\\Virtualizor', 'method' => 'virtualizor', 'api_endpoint_template' => 'https://{host}:4085', 'is_enabled' => 1],
            ['name' => 'custom', 'adapter_class' => 'Integrations\\CustomScript', 'method' => 'custom_script', 'api_endpoint_template' => null, 'is_enabled' => 1],
        ]);

        Schema::create('provisioning_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type', 50);
            $table->json('payload');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'retrying'])->default('pending');
            $table->enum('priority', ['low', 'normal', 'high', 'critical'])->default('normal');
            $table->tinyInteger('attempts')->default(0);
            $table->tinyInteger('max_attempts')->default(3);
            $table->text('last_error')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->string('locked_by', 50)->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->index(['status', 'priority', 'scheduled_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_events');
        Schema::dropIfExists('provisioning_adapters');
        Schema::dropIfExists('product_quota_summary');
        Schema::dropIfExists('resource_allocations');
        Schema::dropIfExists('resource_pools');
        Schema::dropIfExists('product_resources');
        Schema::dropIfExists('resource_types');
    }
};
