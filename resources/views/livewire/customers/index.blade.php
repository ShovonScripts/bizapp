<?php

use App\Livewire\Concerns\TenantScreen;
use App\Models\Business;
use App\Models\Customer;
use App\Support\Phone;
use App\Support\Tenant;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Customers')] class extends Component
{
    use TenantScreen;
    use WithPagination;

    public const CHANNELS = ['whatsapp', 'telegram', 'email', 'sms', 'none'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $filter = 'all';

    public bool $showForm = false;
    public ?int $editingId = null;

    /* The Telegram invite panel. Held as plain strings rather than looked up in the
       template, because building the link writes a token to the database and a
       render must never have side effects — Livewire re-renders on every keystroke
       in the search box. */
    public ?int $linkingId = null;
    public string $linkUrl = '';
    public string $linkMessage = '';

    /* Form fields. The optional ones are ?string defaulting to null, not '',
       because an empty string in `phone` breaks the (business_id, phone) unique
       index the moment a second customer is saved without a number — MySQL
       allows many NULLs there, but only one ''. */
    public string $name = '';
    public ?string $phone = null;
    public ?string $email = null;
    public ?string $whatsapp_number = null;
    public string $preferred_channel = 'telegram';
    public bool $marketing_consent = false;
    public ?string $notes = null;

    /**
     * This screen only makes sense inside one business — see TenantScreen for why
     * the guard is called explicitly here instead of hooked in by the trait.
     */
    public function mount(): void
    {
        $this->guardTenant();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'phone' => [
                'nullable', 'string', 'max:32',
                $this->phoneShapeRule(),

                /* Two non-obvious clauses here.
                   where(business_id): Rule::unique builds a plain query builder, so
                   the BelongsToBusiness global scope does NOT apply. Without it, one
                   salon would be told a number is taken because a different salon has
                   it — leaking that the number exists elsewhere on the platform.
                   whereNull(deleted_at): the index counts soft-deleted rows, but we
                   want a returning customer to be restored (see resolveCustomer)
                   rather than blocked by an error the owner cannot act on. */
                Rule::unique('customers', 'phone')
                    ->where('business_id', Tenant::id())
                    ->whereNull('deleted_at')
                    ->ignore($this->editingId),
            ],

            'email' => ['nullable', 'email', 'max:255'],
            'whatsapp_number' => ['nullable', 'string', 'max:32', $this->phoneShapeRule()],
            'preferred_channel' => ['required', Rule::in(self::CHANNELS)],
            'marketing_consent' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Runs after normalisation, so a value that is still not E.164 is one we could
     * not make sense of. Rejecting it beats saving a number that silently fails to
     * send months later, when nobody connects the missed reminder to this form.
     */
    protected function phoneShapeRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (filled($value) && ! Phone::looksValid($value)) {
                $fail('Please enter a real phone number, e.g. 07700 900123.');
            }
        };
    }

    protected function validationAttributes(): array
    {
        return [
            'whatsapp_number' => 'WhatsApp number',
            'marketing_consent' => 'marketing consent',
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        // findOrFail IS the authorisation check: the global scope means another
        // business's id does not exist from here, so this 404s instead of loading
        // someone else's customer.
        $customer = Customer::findOrFail($id);

        $this->editingId = $customer->id;
        $this->name = $customer->name;
        $this->phone = $customer->phone;
        $this->email = $customer->email;
        $this->whatsapp_number = $customer->whatsapp_number;
        $this->preferred_channel = $customer->preferred_channel;
        $this->marketing_consent = (bool) $customer->marketing_consent;
        $this->notes = $customer->notes;

        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        /* Normalise BEFORE validating, not after.
           The column stores E.164, so validating the raw "07700 900123" finds no
           match against the stored "+447700900123", passes, and then dies on the
           database's unique constraint — a 500 error in the client's face instead
           of a form message. */
        $this->phone = $this->normalisePhoneField($this->phone);
        $this->whatsapp_number = $this->normalisePhoneField($this->whatsapp_number);

        $validated = $this->validate();

        $customer = $this->resolveCustomer();

        // marketing_consent is deliberately not in this list — it goes through
        // syncConsent() below so the evidence fields travel with it.
        $customer->fill([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'],
            'whatsapp_number' => $validated['whatsapp_number'],
            'preferred_channel' => $validated['preferred_channel'],
            'notes' => $validated['notes'],
        ])->save();

        $this->syncConsent($customer);

        $this->showForm = false;
        $this->resetForm();

        $this->toast('Customer saved.');
    }

    /**
     * Blank stays null; anything we cannot parse is handed back unchanged so the
     * validation error has the user's own text to point at rather than an empty box.
     */
    protected function normalisePhoneField(?string $value): ?string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        return Phone::normalise($raw) ?? $raw;
    }

    /**
     * Decide which row this save writes to.
     *
     * The interesting case is a returning customer: their record was deleted, but a
     * soft-deleted row still holds the phone number as far as the unique index is
     * concerned. Restoring beats a dead-end "this number is taken" error, and it
     * reattaches their entire appointment history and spend.
     */
    protected function resolveCustomer(): Customer
    {
        if ($this->editingId !== null) {
            return Customer::findOrFail($this->editingId);
        }

        if (filled($this->phone)) {
            $trashed = Customer::onlyTrashed()->where('phone', $this->phone)->first();

            if ($trashed !== null) {
                $trashed->restore();

                return $trashed;
            }
        }

        return new Customer();
    }

    /**
     * Consent is never a plain column write.
     *
     * Turning it on records when and how it was obtained — "we had consent" is not
     * defensible without that evidence. Turning it off only clears the flag: it does
     * NOT set unsubscribed_at, because that field means "the customer asked us to
     * stop" and it silences appointment reminders too. An owner unticking a box is
     * not the same event as a customer opting out, and conflating them would quietly
     * stop reminders for someone who still wants them.
     */
    protected function syncConsent(Customer $customer): void
    {
        if ($this->marketing_consent && ! $customer->marketing_consent) {
            $customer->recordConsent('staff_entry');

            return;
        }

        if (! $this->marketing_consent && $customer->marketing_consent) {
            $customer->forceFill(['marketing_consent' => false])->save();
        }
    }

    public function delete(int $id): void
    {
        // Soft delete on purpose: appointments, revenue totals and the no-show rate
        // all reference this customer, and a hard delete would rewrite last month's
        // figures. GDPR erasure is a separate, deliberate action — not this button.
        Customer::findOrFail($id)->delete();

        $this->toast('Customer removed.');
    }

    /* ------------------------- Telegram invitations ---------------------- */

    /**
     * Produce this customer's personal invite link.
     *
     * ─── Why the owner sends it, rather than us ─────────────────────────────
     * A Telegram bot cannot message anyone who has not messaged it first. There is
     * no way around that, and no list we can buy our way onto — the customer has to
     * tap /start themselves. The only person with an existing, trusted channel to
     * them is the salon, so the salon forwards the link by WhatsApp or text.
     *
     * The token in it is the only thing identifying the customer to a webhook that
     * arrives with no tenant and no session, so it is per-customer, single-use in
     * practice (the first chat to use it keeps it), and generated here rather than
     * seeded for everybody — a token that exists is a token that can leak.
     */
    public function telegramLink(int $id): void
    {
        // findOrFail against the scoped query is the authorisation check: another
        // business's customer simply does not exist from here.
        $customer = Customer::findOrFail($id);

        $username = ltrim(trim((string) config('messaging.telegram.bot_username')), '@');

        if ($username === '') {
            // Developer-facing, and deliberately not silent: without this the link
            // would be https://t.me/?start=… , which looks plausible and goes nowhere.
            $this->toast('Telegram is not set up yet — add TELEGRAM_BOT_USERNAME to your .env.');

            return;
        }

        $this->linkingId = $customer->id;
        $this->linkUrl = 'https://t.me/'.$username.'?start='.$customer->ensureTelegramLinkToken();

        // Written out in full so the owner can paste it straight into WhatsApp
        // without composing anything. Plain text, no markdown: it is going into
        // someone else's messaging app, not ours.
        $this->linkMessage = "Hi {$customer->name}, tap this link to get your appointment "
            ."reminders from ".$this->businessName()." on Telegram: {$this->linkUrl}";
    }

    public function closeLink(): void
    {
        $this->reset(['linkingId', 'linkUrl', 'linkMessage']);
    }

    protected function businessName(): string
    {
        return Business::find(Tenant::id())?->name ?? 'us';
    }

    public function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'phone', 'email',
            'whatsapp_number', 'preferred_channel', 'marketing_consent', 'notes',
        ]);

        $this->resetValidation();
    }

    public function with(): array
    {
        $query = Customer::query()->search($this->search);

        $query = match ($this->filter) {
            'marketable' => $query->marketable(),
            'lapsed' => $query->lapsed(90),
            'unsubscribed' => $query->whereNotNull('unsubscribed_at'),
            'unlinked' => $query->whereNull('telegram_chat_id'),
            default => $query,
        };

        return [
            'customers' => $query->orderBy('name')->paginate(15),
            'totalCount' => Customer::count(),
            // Passed in rather than read as self::CHANNELS in the template: Blade
            // compiles to a plain function, so `self` there is not this class.
            'channels' => self::CHANNELS,
        ];
    }
}; ?>

<div x-data="{ toast: null }"
     x-on:toast.window="toast = $event.detail.message; setTimeout(() => toast = null, 2500)">

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

        {{-- Rendered here rather than in the layout's $header slot, which does not
             work reliably from a full-page Livewire component. --}}
        <div class="flex items-center justify-between px-4 sm:px-0">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Customers</h1>
                <p class="text-sm text-gray-500">{{ $totalCount }} on your books</p>
            </div>

            <x-primary-button type="button" wire:click="create">Add customer</x-primary-button>
        </div>

        <x-toast />

        <div class="bg-white shadow-sm sm:rounded-lg">

            <div class="p-4 border-b border-gray-100 flex flex-col sm:flex-row gap-3">
                <div class="flex-1">
                    <x-text-input wire:model.live.debounce.300ms="search"
                                  type="search"
                                  class="block w-full"
                                  placeholder="Search name, email or phone…" />
                </div>

                <select wire:model.live="filter"
                        class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                    <option value="all">All customers</option>
                    <option value="marketable">Can receive marketing</option>
                    <option value="lapsed">Not seen in 90 days</option>
                    <option value="unsubscribed">Unsubscribed</option>
                    <option value="unlinked">Telegram not linked</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wider text-gray-500">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">Name</th>
                            <th class="px-4 py-3 text-left font-medium">Phone</th>
                            <th class="px-4 py-3 text-left font-medium">Channel</th>
                            <th class="px-4 py-3 text-left font-medium">Last visit</th>
                            <th class="px-4 py-3 text-right font-medium">Spend</th>
                            <th class="px-4 py-3 text-right font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @forelse ($customers as $customer)
                            <tr wire:key="customer-{{ $customer->id }}" class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ $customer->name }}</div>

                                    @if ($customer->email)
                                        <div class="text-gray-500">{{ $customer->email }}</div>
                                    @endif

                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @if ($customer->unsubscribed_at)
                                            {{-- Shown loudly on purpose: this is the one flag
                                                 staff must never quietly clear. --}}
                                            <span class="inline-flex rounded px-1.5 py-0.5 text-xs font-medium bg-red-50 text-red-700">
                                                Unsubscribed
                                            </span>
                                        @elseif ($customer->marketing_consent)
                                            <span class="inline-flex rounded px-1.5 py-0.5 text-xs font-medium bg-green-50 text-green-700">
                                                Marketing OK
                                            </span>
                                        @endif

                                        @if ($customer->telegramLinked())
                                            <span class="inline-flex rounded px-1.5 py-0.5 text-xs font-medium bg-sky-50 text-sky-700">
                                                Telegram
                                            </span>
                                        @endif
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                                    {{ \App\Support\Phone::forHumans($customer->phone) ?? '—' }}
                                </td>

                                <td class="px-4 py-3 text-gray-700 capitalize">{{ $customer->preferred_channel }}</td>

                                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                                    @if ($customer->last_visit_at)
                                        {{ $customer->last_visit_at->diffForHumans(short: true) }}
                                    @else
                                        <span class="text-gray-400">never</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-right text-gray-700 whitespace-nowrap">
                                    £{{ number_format((float) $customer->total_spend, 2) }}
                                </td>

                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if (! $customer->telegramLinked() && $customer->preferred_channel !== 'none')
                                        {{-- Hidden once they are linked, and for anyone who has
                                             asked for no messages at all: sending them an invite
                                             would be asking a question they already answered. --}}
                                        <button type="button" wire:click="telegramLink({{ $customer->id }})"
                                                class="me-3 font-medium text-sky-600 hover:text-sky-800">Invite</button>
                                    @endif

                                    <button type="button" wire:click="edit({{ $customer->id }})"
                                            class="font-medium text-indigo-600 hover:text-indigo-900">Edit</button>

                                    <button type="button" wire:click="delete({{ $customer->id }})"
                                            wire:confirm="Remove {{ $customer->name }}? Their appointment history is kept."
                                            class="ms-3 text-gray-400 hover:text-red-600">Remove</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-gray-500">
                                    @if ($search !== '' || $filter !== 'all')
                                        No customers match that.
                                    @else
                                        No customers yet. Add your first one to get started.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($customers->hasPages())
                <div class="p-4 border-t border-gray-100">{{ $customers->links() }}</div>
            @endif
        </div>
    </div>

    @if ($showForm)
        <x-form-modal :title="$editingId ? 'Edit customer' : 'Add customer'"
                      :submit-label="$editingId ? 'Save changes' : 'Add customer'">

                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input wire:model="name" id="name" type="text" class="mt-1 block w-full" autofocus />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="phone" value="Phone" />
                        <x-text-input wire:model="phone" id="phone" type="text" class="mt-1 block w-full"
                                      placeholder="07700 900123" />
                        <p class="mt-1 text-xs text-gray-500">Any format — saved as +44…</p>
                        <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="whatsapp_number" value="WhatsApp (if different)" />
                        <x-text-input wire:model="whatsapp_number" id="whatsapp_number" type="text" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('whatsapp_number')" class="mt-2" />
                    </div>
                </div>

                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input wire:model="email" id="email" type="email" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="preferred_channel" value="Preferred channel" />
                    <select wire:model="preferred_channel" id="preferred_channel"
                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        @foreach ($channels as $channel)
                            <option value="{{ $channel }}">
                                {{ $channel === 'none' ? 'No messages' : ucfirst($channel) }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('preferred_channel')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="notes" value="Notes" />
                    <textarea wire:model="notes" id="notes" rows="2"
                              class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                </div>

                <label class="flex items-start gap-2 rounded-md bg-gray-50 p-3">
                    <input type="checkbox" wire:model="marketing_consent"
                           class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <span class="text-sm text-gray-700">
                        Happy to receive offers and news
                        <span class="block text-xs text-gray-500">
                            Only tick this if they actually agreed. Appointment reminders go out
                            either way — this is marketing only.
                        </span>
                    </span>
                </label>
        </x-form-modal>
    @endif

    @if ($linkingId)
        {{-- Not x-form-modal: there is nothing to submit here. The whole panel is a
             clipboard, and the owner's next action happens in WhatsApp. --}}
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             wire:key="tg-invite-{{ $linkingId }}">

            <div class="fixed inset-0 bg-gray-900/50" wire:click="closeLink"></div>

            <div class="relative w-full max-w-lg rounded-lg bg-white shadow-xl"
                 x-data="{ copied: null }"
                 x-on:keydown.escape.window="$wire.closeLink()">

                <div class="p-6 space-y-4">
                    <div>
                        <h2 class="text-lg font-medium text-gray-900">Invite to Telegram</h2>
                        <p class="mt-1 text-sm text-gray-500">
                            Telegram won't let us message someone until they've started the bot
                            themselves, so send them this link. It's personal to them — one link
                            per customer.
                        </p>
                    </div>

                    <div>
                        <x-input-label value="Ready-made message" />
                        <textarea readonly rows="3" x-ref="message"
                                  class="mt-1 block w-full text-sm border-gray-300 bg-gray-50 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                  >{{ $linkMessage }}</textarea>

                        <button type="button"
                                x-on:click="$refs.message.select();
                                            navigator.clipboard?.writeText($refs.message.value);
                                            copied = 'message'; setTimeout(() => copied = null, 2000)"
                                class="mt-2 text-sm font-medium text-indigo-600 hover:text-indigo-900">
                            <span x-show="copied !== 'message'">Copy message</span>
                            <span x-show="copied === 'message'" class="text-green-600">Copied</span>
                        </button>
                    </div>

                    <div>
                        <x-input-label value="Link only" />
                        <input type="text" readonly x-ref="url" value="{{ $linkUrl }}"
                               class="mt-1 block w-full text-sm border-gray-300 bg-gray-50 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">

                        <button type="button"
                                x-on:click="$refs.url.select();
                                            navigator.clipboard?.writeText($refs.url.value);
                                            copied = 'url'; setTimeout(() => copied = null, 2000)"
                                class="mt-2 text-sm font-medium text-indigo-600 hover:text-indigo-900">
                            <span x-show="copied !== 'url'">Copy link</span>
                            <span x-show="copied === 'url'" class="text-green-600">Copied</span>
                        </button>

                        {{-- The clipboard API only exists on https and localhost, so the
                             fields above are selectable and read-only rather than hidden:
                             if the copy button does nothing, ctrl-C still works. --}}
                        <p class="mt-2 text-xs text-gray-500">
                            Nothing is sent from here — paste it into WhatsApp or a text message.
                        </p>
                    </div>
                </div>

                <div class="flex justify-end gap-3 rounded-b-lg bg-gray-50 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeLink">Done</x-secondary-button>
                </div>
            </div>
        </div>
    @endif
</div>
