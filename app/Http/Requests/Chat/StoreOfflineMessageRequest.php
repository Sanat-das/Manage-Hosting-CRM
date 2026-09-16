<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

/**
 * An out-of-hours message from the widget.
 *
 * Extends StartClientChatRequest rather than restating its rules: the identity
 * requirement is the subtle one — name and email are required for a visitor
 * with no customer record and ignored for a signed-in one — and two copies of
 * that condition would eventually disagree. The offline form and the live chat
 * ask the visitor for exactly the same three things, so they validate them the
 * same way.
 *
 * The `email` this collects is not decoration: an offline message becomes a
 * ticket, and the ticket is how the reply gets back to a visitor who has closed
 * the tab.
 */
class StoreOfflineMessageRequest extends StartClientChatRequest
{
    //
}
