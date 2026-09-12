<?php

use App\Livewire\Concerns\TenantScreen;
use App\Models\Business;
use App\Models\Customer;
use App\Support\Phone;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;
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

    /* ---------------------- Customer 360 Drawer -------------------------- */
    public ?int $viewingCustomerId = null;
    public string $profileNotes = '';

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

    /**
     * Empties BOTH the search box and the channel filter.
     *
     * Written as a method rather than inline in wire:click because Livewire evaluates
     * that attribute as "$wire." . $expression -- a single textual prepend. Only the
     * first statement gets the prefix, so a second one like $refresh() is evaluated
     * bare by Alpine, where no such magic exists, and throws in the console.
     */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->filter = 'all';
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
            // Two audiences, two messages. The owner gets something they can act on
            // — ring the person who set this up — and never sees the name of an
            // environment variable. The detail that actually fixes it goes to the
            // log, where the developer is looking. Silence is not an option either
            // way: without this the link would be https://t.me/?start=… , which
            // looks plausible and goes nowhere.
            Log::warning('Telegram invite blocked: messaging.telegram.bot_username is empty.', [
                'business_id' => Tenant::id(),
            ]);

            $this->toast('Telegram invites are not switched on yet. Ask whoever set up your account to finish connecting it.');

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

    public function viewProfile(int $id): void
    {
        $customer = Customer::findOrFail($id);
        $this->viewingCustomerId = $customer->id;
        $this->profileNotes = (string) $customer->notes;
    }

    public function closeProfile(): void
    {
        $this->viewingCustomerId = null;
        $this->profileNotes = '';
    }

    public function saveProfileNotes(): void
    {
        if (! $this->viewingCustomerId) {
            return;
        }

        $customer = Customer::findOrFail($this->viewingCustomerId);
        $customer->update(['notes' => $this->profileNotes]);

        $this->toast('Customer notes updated.');
    }

    public function statusBadge(string $status): string
    {
        return match ($status) {
            \App\Models\Appointment::PENDING => 'bg-amber-50 text-amber-800 ring-amber-200',
            \App\Models\Appointment::CONFIRMED => 'bg-sky-50 text-sky-800 ring-sky-200',
            \App\Models\Appointment::COMPLETED => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            \App\Models\Appointment::NO_SHOW => 'bg-red-50 text-red-800 ring-red-200',
            default => 'bg-gray-100 text-gray-600 ring-gray-200',
        };
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

        $viewingData = null;
        if ($this->viewingCustomerId) {
            $viewingCustomer = Customer::with(['appointments' => fn ($q) => $q->with(['service', 'staffMember'])->orderByDesc('starts_at')])
                ->findOrFail($this->viewingCustomerId);

            $business = Business::find(Tenant::id());
            $cleanPhone = preg_replace('/[^0-9]/', '', (string) ($viewingCustomer->whatsappTarget() ?: $viewingCustomer->phone));
            $bizName = $business?->name ?? 'our team';
            $greeting = rawurlencode("Hi {$viewingCustomer->name}, from {$bizName}!");
            $whatsappUrl = $cleanPhone ? "https://wa.me/{$cleanPhone}?text={$greeting}" : null;

            $blockingAppointments = $viewingCustomer->appointments->whereIn('status', \App\Models\Appointment::BLOCKING);
            $visitsCount = $blockingAppointments->count();
            $totalSpend = (float) ($viewingCustomer->total_spend ?? 0);
            $avgSpend = $visitsCount > 0 ? $totalSpend / $visitsCount : 0.0;

            $favService = $viewingCustomer->appointments
                ->filter(fn ($a) => $a->service)
                ->groupBy('service.name')
                ->sortByDesc(fn ($group) => $group->count())
                ->keys()
                ->first();

            $favStylist = $viewingCustomer->appointments
                ->filter(fn ($a) => $a->staffMember)
                ->groupBy('staffMember.name')
                ->sortByDesc(fn ($group) => $group->count())
                ->keys()
                ->first();

            $viewingData = [
                'customer' => $viewingCustomer,
                'business' => $business,
                'whatsappUrl' => $whatsappUrl,
                'cleanPhone' => $cleanPhone,
                'visitsCount' => $visitsCount,
                'totalSpend' => $totalSpend,
                'avgSpend' => $avgSpend,
                'favService' => $favService,
                'favStylist' => $favStylist,
                'appointments' => $viewingCustomer->appointments,
            ];
        }

        return [
            'customers' => $query->orderBy('name')->paginate(15),
            'totalCount' => Customer::count(),
            // Passed in rather than read as self::CHANNELS in the template: Blade
            // compiles to a plain function, so `self` there is not this class.
            'channels' => self::CHANNELS,
            'viewingData' => $viewingData,
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

        <div class="bg-white shadow-sm sm:rounded-2xl border border-slate-200/80 overflow-hidden">

            <div class="p-4 border-b border-gray-100 flex flex-col sm:flex-row gap-3">
                <div class="flex-1 relative">
                    {{-- No <form> around this one, so Enter cannot submit anything —
                         but on a phone the keyboard still covers the results it just
                         filtered. Blur on Enter hands the screen back. --}}
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <svg class="h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <x-text-input wire:model.live.debounce.300ms="search"
                                  type="search"
                                  enterkeyhint="search"
                                  x-on:keydown.enter.prevent="$event.target.blur()"
                                  class="block w-full pl-9"
                                  placeholder="Search name, email or phone…" />
                </div>

                <x-select-input wire:model.live="filter" aria-label="Filter the customer list">
                    <option value="all">All customers</option>
                    <option value="marketable">Can receive marketing</option>
                    <option value="lapsed">Not seen in 90 days</option>
                    <option value="unsubscribed">Unsubscribed</option>
                    <option value="unlinked">Telegram not linked</option>
                </x-select-input>
            </div>

            @php
                // Same button vocabulary as the diary's row actions, so the owner
                // learns one interaction and it works everywhere.
                $act = 'inline-flex items-center justify-center min-h-touch rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600';
                $actDanger = 'inline-flex items-center justify-center min-h-touch rounded-lg border border-red-200 bg-white px-3 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600';
            @endphp

            @if ($customers->isEmpty())
                <div class="px-4 py-16 text-center">
                    @if ($search !== '' || $filter !== 'all')
                        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-slate-100 text-slate-500 mb-3 shadow-xs ring-1 ring-slate-200/80 transition-transform duration-300 hover:scale-105">
                            <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                        </div>
                        <p class="text-gray-500 font-medium">No customers match that.</p>
                        <button type="button" wire:click="clearFilters" class="mt-3 inline-flex items-center justify-center min-h-touch rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                            Clear filters
                        </button>
                    @else
                        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-mulberry-50 text-mulberry-700 mb-3 shadow-xs ring-1 ring-mulberry-100 transition-transform duration-300 hover:scale-105">
                            <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                            </svg>
                        </div>
                        <p class="text-gray-500 font-medium">No customers yet.</p>
                        <p class="mt-1 text-sm text-gray-400">Add the people you see regularly and the reminders take care of themselves.</p>
                        <x-primary-button type="button" wire:click="create" class="mt-4">
                            <svg class="h-4 w-4 -ml-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                            Add your first customer
                        </x-primary-button>
                    @endif
                </div>
            @else

            {{--
                ─── Phone: cards ───────────────────────────────────────────────
                Six columns cannot survive a 390px screen. The table below is kept
                for desktop, where comparing across rows is the point; here the name
                leads, the phone number is a tap-to-call link, and everything else is
                one line of context underneath.

                wire:key is prefixed differently from the table's: both lists sit in
                the DOM at once — one is only hidden by CSS — and Livewire needs every
                key in a component to be unique.
            --}}
            <ul class="divide-y divide-gray-100 sm:hidden">
                @foreach ($customers as $customer)
                    <li wire:key="customer-card-{{ $customer->id }}" x-data="{ actions: false }" class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3 min-w-0 flex-1 cursor-pointer" wire:click="viewProfile({{ $customer->id }})">
                                <span class="avatar-initials mt-0.5">{{ mb_substr($customer->name, 0, 1) }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium text-gray-900 hover:text-mulberry-700 transition-colors">{{ $customer->name }}</div>

                                @if ($customer->email)
                                    <div class="mt-0.5 text-sm text-gray-500">{{ $customer->email }}</div>
                                @endif

                                @if ($customer->phone)
                                    {{-- tel: because on a phone the answer to "who is this?" is
                                         usually "ring them". --}}
                                    <a href="tel:{{ $customer->phone }}"
                                       class="mt-1 inline-flex min-h-touch items-center rounded-lg text-sm font-medium text-mulberry-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                                        {{ \App\Support\Phone::forHumans($customer->phone) }}
                                    </a>
                                @else
                                    <p class="mt-1 text-sm text-gray-500">No phone number — cannot be reminded</p>
                                @endif
                                </div>
                            </div>

                            <button type="button" @click="actions = ! actions"
                                    :aria-expanded="actions ? 'true' : 'false'"
                                    aria-label="More actions for {{ $customer->name }}"
                                    class="-me-1 inline-flex min-h-touch min-w-touch shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M10 6a1.5 1.5 0 110-3 1.5 1.5 0 010 3zM10 11.5a1.5 1.5 0 110-3 1.5 1.5 0 010 3zM10 17a1.5 1.5 0 110-3 1.5 1.5 0 010 3z" />
                                </svg>
                            </button>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500">
                            <span>
                                Last visit
                                <span class="font-medium text-gray-900">
                                    {{ $customer->last_visit_at ? $customer->last_visit_at->diffForHumans(short: true) : 'never' }}
                                </span>
                            </span>

                            <span>
                                Spent
                                <span class="font-medium tabular-nums text-gray-900">£{{ number_format((float) $customer->total_spend, 2) }}</span>
                            </span>

                            <span>
                                @if ($customer->preferred_channel === 'none')
                                    <span class="font-medium text-gray-900">No messages</span>
                                @else
                                    <span class="font-medium text-gray-900">{{ \App\Support\Channel::label($customer->preferred_channel) }}</span>
                                @endif
                            </span>
                        </div>

                        @if ($customer->unsubscribed_at || $customer->marketing_consent || $customer->telegramLinked())
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @if ($customer->unsubscribed_at)
                                    <span class="inline-flex rounded px-1.5 py-0.5 text-xs font-medium bg-red-50 text-red-700 ring-1 ring-red-600/10">Unsubscribed</span>
                                @elseif ($customer->marketing_consent)
                                    <span class="inline-flex rounded px-1.5 py-0.5 text-xs font-medium bg-green-50 text-green-700 ring-1 ring-green-600/10">Marketing OK</span>
                                @endif

                                @if ($customer->telegramLinked())
                                    <span class="inline-flex rounded px-1.5 py-0.5 text-xs font-medium bg-sky-50 text-sky-700 ring-1 ring-sky-600/10">Telegram</span>
                                @endif
                            </div>
                        @endif

                        <div x-show="actions" x-collapse style="display: none"
                             class="mt-3 flex flex-wrap gap-2 border-t border-gray-100 pt-3">
                            <button type="button" wire:click="viewProfile({{ $customer->id }})" class="{{ $act }}">View Profile</button>
                            <button type="button" wire:click="edit({{ $customer->id }})" class="{{ $act }}">Edit</button>

                            @if (! $customer->telegramLinked() && $customer->preferred_channel !== 'none')
                                <button type="button" wire:click="telegramLink({{ $customer->id }})" class="{{ $act }}">Invite to Telegram</button>
                            @endif

                            <button type="button" wire:click="delete({{ $customer->id }})"
                                    wire:confirm="Remove {{ $customer->name }}? Their appointment history is kept."
                                    class="{{ $actDanger }}">Remove</button>
                        </div>
                    </li>
                @endforeach
            </ul>

            {{-- ─── Desktop: the table ───────────────────────────────────────── --}}
            <div class="hidden overflow-x-auto sm:block">
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

                    <tbody class="divide-y divide-gray-100 bg-white">
                        @foreach ($customers as $customer)
                            <tr wire:key="customer-row-{{ $customer->id }}" class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 cursor-pointer" wire:click="viewProfile({{ $customer->id }})">
                                    <div class="flex items-center gap-3">
                                        <span class="avatar-initials--sm avatar-initials">{{ mb_substr($customer->name, 0, 1) }}</span>
                                        <div>
                                            <div class="font-medium text-gray-900 hover:text-mulberry-700 transition-colors">{{ $customer->name }}</div>

                                    @if ($customer->email)
                                        <div class="text-gray-500">{{ $customer->email }}</div>
                                    @endif

                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @if ($customer->unsubscribed_at)
                                            <span class="inline-flex rounded px-1.5 py-0.5 text-xs font-medium bg-red-50 text-red-700 ring-1 ring-red-600/10">
                                                Unsubscribed
                                            </span>
                                        @elseif ($customer->marketing_consent)
                                            <span class="inline-flex rounded px-1.5 py-0.5 text-xs font-medium bg-green-50 text-green-700 ring-1 ring-green-600/10">
                                                Marketing OK
                                            </span>
                                        @endif

                                        @if ($customer->telegramLinked())
                                            <span class="inline-flex rounded px-1.5 py-0.5 text-xs font-medium bg-sky-50 text-sky-700 ring-1 ring-sky-600/10">
                                                Telegram
                                            </span>
                                        @endif
                                    </div>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                                    {{ \App\Support\Phone::forHumans($customer->phone) ?? '—' }}
                                </td>

                                <td class="px-4 py-3 text-gray-700">{{ \App\Support\Channel::label($customer->preferred_channel) }}</td>

                                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                                    @if ($customer->last_visit_at)
                                        {{ $customer->last_visit_at->diffForHumans(short: true) }}
                                    @else
                                        <span class="text-gray-500">never</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-right text-gray-700 tabular-nums whitespace-nowrap">
                                    £{{ number_format((float) $customer->total_spend, 2) }}
                                </td>

                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <button type="button" wire:click="viewProfile({{ $customer->id }})"
                                            class="me-2 rounded-md px-2.5 py-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                                        Profile
                                    </button>

                                    @if (! $customer->telegramLinked() && $customer->preferred_channel !== 'none')
                                        <button type="button" wire:click="telegramLink({{ $customer->id }})"
                                                class="me-3 rounded-md px-2.5 py-1.5 text-sm font-medium text-sky-700 hover:bg-sky-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-600">
                                            Invite
                                        </button>
                                    @endif

                                    <button type="button" wire:click="edit({{ $customer->id }})"
                                            class="me-3 rounded-md px-2.5 py-1.5 text-sm font-medium text-mulberry-700 hover:bg-mulberry-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                                        Edit
                                    </button>

                                    <button type="button" wire:click="delete({{ $customer->id }})"
                                            wire:confirm="Remove {{ $customer->name }}? Their appointment history is kept."
                                            class="rounded-md px-2.5 py-1.5 text-sm font-medium text-gray-500 hover:text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600">
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

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
                    <p class="mt-1 text-xs text-gray-500">Optional. Used for email reminders only.</p>
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="preferred_channel" value="Preferred channel" />
                    <x-select-input wire:model="preferred_channel" id="preferred_channel" class="mt-1 block w-full">
                        @foreach ($channels as $channel)
                            <option value="{{ $channel }}">{{ \App\Support\Channel::label($channel) }}</option>
                        @endforeach
                    </x-select-input>
                    <p class="mt-1 text-xs text-gray-500">
                        Telegram = free reminders. WhatsApp = requires Meta business setup. SMS = paid per message.
                        Choose none to silence all reminders for this customer.
                    </p>
                    <x-input-error :messages="$errors->get('preferred_channel')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="notes" value="Notes" />
                    <textarea wire:model="notes" id="notes" rows="2"
                              class="mt-1 block w-full rounded-lg border-gray-300 text-base shadow-sm focus:border-mulberry-600 focus:ring-mulberry-600 sm:text-sm"></textarea>
                    <p class="mt-1 text-xs text-gray-500">Private staff notes — not sent to the customer.</p>
                    <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                </div>

                <label class="flex items-start gap-2 rounded-md bg-gray-50 p-3">
                    <input type="checkbox" wire:model="marketing_consent"
                           class="mt-0.5 rounded border-gray-300 text-mulberry-700 focus:ring-mulberry-600">
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
        <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4"
             wire:key="tg-invite-{{ $linkingId }}">

            {{-- Backdrop dismiss is kept here, unlike <x-form-modal>: nothing is lost by
                 closing this panel — the link is already saved and reopening shows the
                 same one — so the usual "a stray tap threw away my typing" risk does
                 not apply. --}}
            <div class="fixed inset-0 bg-gray-900/50" wire:click="closeLink" aria-hidden="true"></div>

            <div class="relative flex max-h-[90dvh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:max-h-[85vh] sm:max-w-lg sm:rounded-lg"
                 x-data="{
                     copied: null,
                     init() {
                         document.body.classList.add('overflow-hidden');
                     },
                     destroy() {
                         document.body.classList.remove('overflow-hidden');
                     },
                     copy(ref) {
                         this.$refs[ref].select();
                         navigator.clipboard?.writeText(this.$refs[ref].value);
                         this.copied = ref;
                         setTimeout(() => this.copied = null, 2000);
                     },
                 }"
                 x-on:keydown.escape.window="$wire.closeLink()"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="tg-invite-title">

                <div class="flex-1 space-y-4 overflow-y-auto p-5">
                    <div>
                        <h2 id="tg-invite-title" class="text-lg font-semibold text-gray-900">Invite to Telegram</h2>
                        <p class="mt-1 text-sm text-gray-500">
                            Telegram won't let us message someone until they've started the bot
                            themselves, so send them this link. It's personal to them — one link
                            per customer.
                        </p>
                    </div>

                    <div>
                        <x-input-label value="Ready-made message" />
                        <textarea readonly rows="3" x-ref="message"
                                  class="mt-1 block w-full rounded-lg border-gray-300 bg-gray-50 text-base shadow-sm focus:border-mulberry-600 focus:ring-mulberry-600 sm:text-sm"
                                  >{{ $linkMessage }}</textarea>

                        {{-- One label swapped by Alpine rather than two spans toggled: with
                             two, "Copied" was in the DOM and visible for the moment before
                             Alpine booted, so the button flashed the wrong word on load. --}}
                        <button type="button" x-on:click="copy('message')"
                                class="mt-2 inline-flex min-h-touch items-center rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600"
                                x-text="copied === 'message' ? 'Copied' : 'Copy message'">Copy message</button>
                    </div>

                    <div>
                        <x-input-label value="Link only" />
                        <input type="text" readonly x-ref="url" value="{{ $linkUrl }}"
                               class="mt-1 block w-full min-h-touch rounded-lg border-gray-300 bg-gray-50 text-base shadow-sm focus:border-mulberry-600 focus:ring-mulberry-600 sm:text-sm">

                        <button type="button" x-on:click="copy('url')"
                                class="mt-2 inline-flex min-h-touch items-center rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600"
                                x-text="copied === 'url' ? 'Copied' : 'Copy link'">Copy link</button>

                        {{-- The clipboard API only exists on https and localhost, so the
                             fields above are selectable and read-only rather than hidden:
                             if the copy button does nothing, ctrl-C still works. --}}
                        <p class="mt-2 text-xs text-gray-500">
                            Nothing is sent from here — paste it into WhatsApp or a text message.
                        </p>
                    </div>
                </div>

                <div class="flex shrink-0 justify-end gap-3 border-t border-gray-200 bg-gray-50 px-5 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                    <x-secondary-button type="button" wire:click="closeLink">Done</x-secondary-button>
                </div>
            </div>
        </div>
    @endif

    {{-- ---------------------------------------------------------------
         Customer 360 Profile Drawer
    ---------------------------------------------------------------- --}}
    @if ($viewingCustomerId && $viewingData)
        @php
            $cust = $viewingData['customer'];
        @endphp
        <div class="fixed inset-0 z-50 overflow-hidden" aria-labelledby="customer-360-title" role="dialog" aria-modal="true">
            {{-- Backdrop blur --}}
            <div class="fixed inset-0 bg-gray-900/50 overlay-blur transition-opacity"
                 wire:click="closeProfile"></div>

            <div class="fixed inset-y-0 right-0 flex max-w-full pl-10">
                <div class="w-screen max-w-lg transform transition ease-in-out duration-300 bg-white shadow-2xl flex flex-col">

                    {{-- Drawer Header --}}
                    <div class="p-6 border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white flex items-start justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <span class="h-14 w-14 rounded-2xl flex items-center justify-center text-xl font-bold text-white shadow-md bg-gradient-to-tr from-mulberry-700 to-mulberry-500">
                                {{ mb_substr($cust->name, 0, 1) }}
                            </span>
                            <div>
                                <h2 id="customer-360-title" class="text-xl font-bold text-gray-900 leading-tight">
                                    {{ $cust->name }}
                                </h2>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    Customer since {{ $cust->created_at->format('M Y') }} ·
                                    @if ($cust->last_visit_at)
                                        Last seen {{ $cust->last_visit_at->diffForHumans() }}
                                    @else
                                        Never visited yet
                                    @endif
                                </p>
                            </div>
                        </div>

                        <button type="button" wire:click="closeProfile"
                                class="rounded-lg p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
                                aria-label="Close profile">
                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>

                    {{-- 1-Tap Direct Outreach Actions --}}
                    <div class="px-6 py-3.5 bg-gray-50/70 border-b border-gray-100 flex flex-wrap items-center gap-2">
                        @if ($viewingData['whatsappUrl'])
                            <a href="{{ $viewingData['whatsappUrl'] }}" target="_blank" rel="noopener noreferrer"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-semibold shadow-2xs hover:bg-emerald-700 transition-colors">
                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                                WhatsApp
                            </a>
                        @endif

                        @if ($cust->phone)
                            <a href="tel:{{ $cust->phone }}"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sky-50 text-sky-800 border border-sky-200 text-xs font-semibold hover:bg-sky-100 transition-colors">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
                                Call
                            </a>

                            <a href="sms:{{ $cust->phone }}"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 border border-gray-200 text-xs font-semibold hover:bg-gray-200 transition-colors">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>
                                SMS
                            </a>
                        @endif

                        @if ($cust->email)
                            <a href="mailto:{{ $cust->email }}"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-purple-50 text-purple-800 border border-purple-200 text-xs font-semibold hover:bg-purple-100 transition-colors">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                                Email
                            </a>
                        @endif

                        @if (! $cust->telegramLinked() && $cust->preferred_channel !== 'none')
                            <button type="button" wire:click="telegramLink({{ $cust->id }})"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sky-50 text-sky-700 border border-sky-200 text-xs font-semibold hover:bg-sky-100 transition-colors">
                                Invite to Telegram
                            </button>
                        @endif
                    </div>

                    {{-- Scrollable Content Body --}}
                    <div class="flex-1 overflow-y-auto p-6 space-y-6">

                        {{-- KPI Cards --}}
                        <div class="grid grid-cols-3 gap-3">
                            <div class="rounded-xl border border-gray-100 bg-gray-50/70 p-3.5 text-center">
                                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">Spend</span>
                                <div class="mt-1 text-lg font-bold text-gray-900 tabular-nums">
                                    £{{ number_format($viewingData['totalSpend'], 2) }}
                                </div>
                            </div>
                            <div class="rounded-xl border border-gray-100 bg-gray-50/70 p-3.5 text-center">
                                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">Visits</span>
                                <div class="mt-1 text-lg font-bold text-gray-900 tabular-nums">
                                    {{ $viewingData['visitsCount'] }}
                                </div>
                            </div>
                            <div class="rounded-xl border border-gray-100 bg-gray-50/70 p-3.5 text-center">
                                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">Avg Value</span>
                                <div class="mt-1 text-lg font-bold text-gray-900 tabular-nums">
                                    £{{ number_format($viewingData['avgSpend'], 2) }}
                                </div>
                            </div>
                        </div>

                        {{-- Preferences & Top Choices --}}
                        <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-2xs space-y-3">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500">Customer Insights</h3>
                            <div class="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <span class="text-xs text-gray-400 block">Favorite Service</span>
                                    <span class="font-medium text-gray-800">{{ $viewingData['favService'] ?? 'None yet' }}</span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-400 block">Preferred Stylist</span>
                                    <span class="font-medium text-gray-800">{{ $viewingData['favStylist'] ?? 'None yet' }}</span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-400 block">Preferred Channel</span>
                                    <span class="font-medium text-gray-800">{{ \App\Support\Channel::label($cust->preferred_channel) }}</span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-400 block">Reminder Status</span>
                                    <span class="font-medium {{ $cust->canReceiveTransactional() ? 'text-emerald-700' : 'text-amber-700' }}">
                                        {{ $cust->canReceiveTransactional() ? 'Can receive reminders' : 'Opted out' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {{-- Quick Notes Editor --}}
                        <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-2xs space-y-2">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500">Notes & Preferences</h3>
                                <button type="button" wire:click="saveProfileNotes"
                                        class="text-xs font-semibold text-mulberry-700 hover:text-mulberry-900">
                                    Save notes
                                </button>
                            </div>
                            <textarea wire:model="profileNotes" rows="2"
                                      placeholder="Color formula, preferred tea, allergies, family notes..."
                                      class="block w-full rounded-lg border-gray-200 bg-gray-50 text-sm focus:border-mulberry-600 focus:ring-mulberry-600"></textarea>
                        </div>

                        {{-- Booking History Timeline --}}
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500">
                                    Appointment History ({{ $viewingData['appointments']->count() }})
                                </h3>
                                <a href="/appointments" class="text-xs font-semibold text-mulberry-700 hover:underline">
                                    Go to Diary →
                                </a>
                            </div>

                            <div class="space-y-2.5">
                                @forelse ($viewingData['appointments'] as $appt)
                                    <div class="p-3 rounded-xl border border-gray-100 bg-white shadow-2xs flex items-start justify-between gap-3">
                                        <div class="space-y-1">
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs font-bold text-gray-900 tabular-nums">
                                                    {{ $appt->starts_at->format('D j M Y, H:i') }}
                                                </span>
                                                <span class="inline-flex items-center rounded px-1.5 py-0.2 text-2xs font-medium ring-1 ring-inset {{ $this->statusBadge($appt->status) }}">
                                                    {{ $appt->statusLabel() }}
                                                </span>
                                            </div>
                                            <p class="text-sm font-medium text-gray-800">
                                                {{ $appt->service?->name ?? 'Custom Service' }} · £{{ number_format((float) $appt->price, 2) }}
                                            </p>
                                            @if ($appt->staffMember)
                                                <p class="text-xs text-gray-500 flex items-center gap-1">
                                                    <span class="h-2 w-2 rounded-full inline-block" style="background-color: {{ $appt->staffMember->color }}"></span>
                                                    {{ $appt->staffMember->name }}
                                                </p>
                                            @endif
                                            @if ($appt->notes)
                                                <p class="text-xs text-gray-400 italic">{{ $appt->notes }}</p>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="py-6 text-center text-xs text-gray-400 border border-dashed border-gray-200 rounded-xl">
                                        No previous appointments on record.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    {{-- Drawer Footer --}}
                    <div class="p-4 border-t border-gray-100 bg-gray-50/70 flex items-center justify-between gap-3">
                        <button type="button" wire:click="edit({{ $cust->id }})"
                                class="text-xs font-semibold text-gray-700 hover:text-gray-900 px-3 py-2 rounded-lg hover:bg-gray-100 transition-colors">
                            Edit Full Details
                        </button>
                        <x-secondary-button wire:click="closeProfile">
                            Close
                        </x-secondary-button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
