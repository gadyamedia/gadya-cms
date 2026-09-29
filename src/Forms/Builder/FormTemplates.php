<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Models\Form;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Starting points for the forms a local business actually needs. Each
 * one is only a draft to change: the client picks the nearest, renames
 * the questions into her own words and publishes it.
 */
class FormTemplates
{
    /**
     * @return array<string, array{title: string, description: string, icon: string, fields: list<array<string, mixed>>, messages?: array<string, string>, settings?: array<string, mixed>}>
     */
    public function all(): array
    {
        $name = ['type' => 'name', 'key' => 'name', 'label' => 'Your name', 'required' => true];
        $email = ['type' => 'email', 'key' => 'email', 'label' => 'Email address', 'required' => true, 'width' => 'half'];
        $phone = ['type' => 'phone', 'key' => 'phone', 'label' => 'Phone number', 'width' => 'half'];
        $choices = fn (string ...$labels): array => array_map(fn (string $label): array => ['label' => $label], $labels);

        return [
            'contact' => [
                'title' => 'Contact us',
                'description' => 'Name, email, phone and a message. The one every site needs.',
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'fields' => [
                    $name, $email, $phone,
                    ['type' => 'long_text', 'key' => 'message', 'label' => 'How can we help?', 'required' => true],
                ],
            ],
            'quote' => [
                'title' => 'Request a quote',
                'description' => 'What they need, where, when and roughly what it should cost - with photos of the job.',
                'icon' => 'heroicon-o-document-currency-dollar',
                'fields' => [
                    $name, $email, $phone,
                    ['type' => 'select', 'key' => 'service', 'label' => 'What do you need?', 'required' => true, 'options' => $choices('Repair', 'Installation', 'Maintenance', 'Something else')],
                    ['type' => 'address', 'key' => 'address', 'label' => 'Where is the job?', 'required' => true],
                    ['type' => 'date', 'key' => 'preferred_date', 'label' => 'When would suit you?', 'width' => 'half', 'rules' => ['future' => true]],
                    ['type' => 'currency', 'key' => 'budget', 'label' => 'Rough budget', 'width' => 'half'],
                    ['type' => 'long_text', 'key' => 'details', 'label' => 'Tell us about the job', 'required' => true],
                    ['type' => 'image', 'key' => 'photos', 'label' => 'Photos of the job', 'help' => 'Optional, but they help us give a better price.', 'rules' => ['multiple' => true]],
                ],
                'messages' => ['success' => 'Thank you. We will look at the details and send you a quote, usually within one working day.'],
            ],
            'booking' => [
                'title' => 'Book an appointment',
                'description' => 'A service, a day and a time, and how to reach them to confirm.',
                'icon' => 'heroicon-o-calendar-days',
                'fields' => [
                    ['type' => 'select', 'key' => 'service', 'label' => 'What would you like to book?', 'required' => true, 'options' => $choices('Haircut', 'Color', 'Blow-dry', 'Consultation')],
                    ['type' => 'date', 'key' => 'date', 'label' => 'Preferred day', 'required' => true, 'width' => 'half', 'rules' => ['future' => true]],
                    ['type' => 'radio', 'key' => 'time_of_day', 'label' => 'Preferred time', 'required' => true, 'width' => 'half', 'options' => $choices('Morning', 'Afternoon', 'Evening')],
                    ['type' => 'page_break', 'label' => 'Your details'],
                    $name, $email,
                    ['type' => 'phone', 'key' => 'phone', 'label' => 'Phone number', 'required' => true, 'width' => 'half'],
                    ['type' => 'long_text', 'key' => 'notes', 'label' => 'Anything we should know?'],
                ],
                'messages' => ['first_step' => 'Your appointment', 'success' => 'Thank you. This is a request, not a confirmed booking - we will call or email to confirm your time.'],
            ],
            'catering' => [
                'title' => 'Catering order',
                'description' => 'The event, the numbers, the menu and delivery, over three short steps.',
                'icon' => 'heroicon-o-cake',
                'fields' => [
                    ['type' => 'select', 'key' => 'event_type', 'label' => 'What is the occasion?', 'required' => true, 'options' => $choices('Birthday', 'Office lunch', 'Wedding', 'Graduation', 'Other')],
                    ['type' => 'datetime', 'key' => 'event_date', 'label' => 'Date and time', 'required' => true, 'width' => 'half', 'rules' => ['future' => true]],
                    ['type' => 'number', 'key' => 'guests', 'label' => 'Number of guests', 'required' => true, 'width' => 'half', 'rules' => ['min' => 10]],
                    ['type' => 'page_break', 'label' => 'The food'],
                    ['type' => 'checkboxes', 'key' => 'menu', 'label' => 'What would you like?', 'required' => true, 'options' => $choices('Hot trays', 'Sandwich platters', 'Salads', 'Desserts', 'Drinks')],
                    ['type' => 'checkboxes', 'key' => 'dietary', 'label' => 'Dietary needs', 'options' => $choices('Vegetarian', 'Vegan', 'Gluten-free', 'Nut allergy')],
                    ['type' => 'yes_no', 'key' => 'delivery', 'label' => 'Should we deliver?', 'required' => true],
                    ['type' => 'address', 'key' => 'delivery_address', 'label' => 'Delivery address', 'required' => true,
                        'logic' => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'delivery', 'operator' => 'equals', 'value' => 'yes']]]],
                    ['type' => 'page_break', 'label' => 'Your details'],
                    $name, $email,
                    ['type' => 'phone', 'key' => 'phone', 'label' => 'Phone number', 'required' => true, 'width' => 'half'],
                    ['type' => 'long_text', 'key' => 'notes', 'label' => 'Anything else?'],
                ],
                'messages' => ['first_step' => 'Your event', 'success' => 'Thank you. We will be in touch within one working day to confirm your order and the price.'],
                'settings' => ['save_later' => true],
            ],
            'job' => [
                'title' => 'Job application',
                'description' => 'The role, when they can start, experience and a résumé.',
                'icon' => 'heroicon-o-briefcase',
                'fields' => [
                    ['type' => 'select', 'key' => 'position', 'label' => 'Which position?', 'required' => true, 'options' => $choices('Front of house', 'Kitchen', 'Delivery driver', 'Other')],
                    $name, $email,
                    ['type' => 'phone', 'key' => 'phone', 'label' => 'Phone number', 'required' => true, 'width' => 'half'],
                    ['type' => 'date', 'key' => 'start_date', 'label' => 'When could you start?', 'width' => 'half'],
                    ['type' => 'checkboxes', 'key' => 'availability', 'label' => 'When can you work?', 'options' => $choices('Weekday days', 'Weekday evenings', 'Weekends')],
                    ['type' => 'long_text', 'key' => 'experience', 'label' => 'Tell us about your experience'],
                    ['type' => 'file', 'key' => 'resume', 'label' => 'Your résumé', 'rules' => ['accept' => ['pdf', 'doc', 'docx']]],
                    ['type' => 'yes_no', 'key' => 'authorized', 'label' => 'Are you authorized to work in the United States?', 'required' => true],
                ],
                'messages' => ['success' => 'Thank you for applying. We read every application and will be in touch if there is a fit.'],
            ],
            'rsvp' => [
                'title' => 'Event RSVP',
                'description' => 'Coming or not, how many, and any dietary needs.',
                'icon' => 'heroicon-o-ticket',
                'fields' => [
                    $name, $email,
                    ['type' => 'radio', 'key' => 'attending', 'label' => 'Will you be there?', 'required' => true, 'options' => $choices('Yes, I will be there', 'Sorry, I cannot make it')],
                    ['type' => 'number', 'key' => 'guests', 'label' => 'How many of you, including you?', 'required' => true, 'rules' => ['min' => 1, 'max' => 10],
                        'logic' => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'attending', 'operator' => 'equals', 'value' => 'Yes, I will be there']]]],
                    ['type' => 'long_text', 'key' => 'dietary', 'label' => 'Any dietary needs?',
                        'logic' => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'attending', 'operator' => 'equals', 'value' => 'Yes, I will be there']]]],
                    ['type' => 'mailing_list', 'key' => 'news', 'label' => 'Tell me about future events'],
                ],
                'messages' => ['success' => 'Thank you. We have your reply.'],
            ],
            'feedback' => [
                'title' => 'Feedback',
                'description' => 'A star rating, the "would you recommend us" score, and what to do better.',
                'icon' => 'heroicon-o-star',
                'fields' => [
                    ['type' => 'rating', 'key' => 'rating', 'label' => 'How was your visit?', 'required' => true],
                    ['type' => 'scale', 'key' => 'recommend', 'label' => 'How likely are you to recommend us to a friend?', 'required' => true, 'rules' => ['min' => 0, 'max' => 10, 'low_label' => 'Not likely', 'high_label' => 'Very likely']],
                    ['type' => 'long_text', 'key' => 'better', 'label' => 'What could we do better?'],
                    ['type' => 'email', 'key' => 'email', 'label' => 'Email, if you would like a reply', 'help' => 'Optional.'],
                ],
                'messages' => ['success' => 'Thank you. Every answer is read.'],
                'settings' => ['analytics_event' => null],
            ],
            'newsletter' => [
                'title' => 'Newsletter sign-up',
                'description' => 'A name, an email and a tick to join the mailing list.',
                'icon' => 'heroicon-o-envelope-open',
                'fields' => [
                    ['type' => 'short_text', 'key' => 'first_name', 'label' => 'First name', 'width' => 'half'],
                    ['type' => 'email', 'key' => 'email', 'label' => 'Email address', 'required' => true, 'width' => 'half'],
                    ['type' => 'mailing_list', 'key' => 'news', 'label' => 'Yes, send me news and offers', 'required' => true, 'default' => '1'],
                ],
                'messages' => ['submit' => 'Sign up', 'success' => 'Thank you. You are on the list.'],
                'settings' => ['analytics_event' => null],
            ],
            'callback' => [
                'title' => 'Call me back',
                'description' => 'A name and a number, with consent to be rung - by a person or the AI receptionist.',
                'icon' => 'heroicon-o-phone-arrow-down-left',
                'fields' => [
                    ['type' => 'short_text', 'key' => 'name', 'label' => 'Your name', 'required' => true],
                    ['type' => 'phone', 'key' => 'phone', 'label' => 'Phone number', 'required' => true],
                    ['type' => 'radio', 'key' => 'best_time', 'label' => 'Best time to call', 'options' => $choices('As soon as possible', 'Morning', 'Afternoon', 'Evening')],
                    ['type' => 'long_text', 'key' => 'message', 'label' => 'What is it about?'],
                    ['type' => 'consent', 'key' => 'consent', 'label' => 'I agree to {business} calling me back about my enquiry on the number above. The call may be made by an automated AI assistant, and I can ask not to be called again at any time.', 'required' => true, 'rules' => ['callback' => true]],
                ],
                'messages' => ['submit' => 'Call me back', 'success' => 'Thank you. We will call you shortly.'],
                'settings' => ['callback' => true],
            ],
        ];
    }

    /**
     * A draft form made from a template, with an address of its own.
     */
    public function create(string $template, ?string $title = null): Form
    {
        $definition = $this->all()[$template] ?? throw new InvalidArgumentException("There is no form template called [{$template}].");
        $title = trim((string) $title) !== '' ? trim((string) $title) : $definition['title'];
        $business = (string) config('gadya-cms.brand.name', config('app.name'));

        $fields = array_map(function (array $field) use ($business): array {
            if (isset($field['label'])) {
                $field['label'] = str_replace('{business}', $business, (string) $field['label']);
            }

            return $field;
        }, $definition['fields']);

        return Form::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'title' => $title,
            'slug' => $this->availableSlug(Str::slug($title) ?: $template),
            'description' => null,
            'status' => Form::STATUS_DRAFT,
            'template' => $template,
            'fields' => FormSchema::normalise($fields),
            'messages' => $definition['messages'] ?? [],
            'settings' => $definition['settings'] ?? [],
            'created_by' => auth()->id(),
        ]);
    }

    public function availableSlug(string $slug): string
    {
        $slug = Str::limit(Str::slug($slug) ?: 'form', 70, '');
        $candidate = $slug;
        $suffix = 2;

        while (Form::query()->forCurrentSite()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$suffix++;
        }

        return $candidate;
    }
}
