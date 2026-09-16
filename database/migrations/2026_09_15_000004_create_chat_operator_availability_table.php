<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An operator's explicit "am I taking chats" state.
 *
 * This is NOT presence. `ChatPresence` answers "is this person's browser
 * connected", which it keeps in the cache with a 90-second heartbeat — the
 * right home for something that is true only while a tab is open. Availability
 * is the opposite kind of fact: a deliberate statement that outlives the tab,
 * the browser restart and the cache flush. Storing it alongside presence would
 * mean "Away" silently expiring back to "Available" 90 seconds after someone
 * closed their laptop, which is exactly the lie this feature exists to stop.
 *
 * One row per user, so the state cannot fork.
 *
 * There is no row for most users and that is the normal case: absent means
 * `available`, so an install that never touches the control behaves exactly as
 * it did before this table existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_operator_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->enum('state', ['available', 'away', 'busy'])->default('available');

            // What they typed in the box: "back at 3", "on a call". Shown to
            // other staff only — never to a customer.
            $table->string('note')->nullable();

            // Separate from updated_at: a note edited without a state change
            // must not read as a fresh state change in the roster.
            $table->timestamp('state_changed_at')->nullable();
            $table->timestamps();

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_operator_availability');
    }
};
