<?php

namespace Gadya\Cms\Forms\Destinations;

use Gadya\Cms\Forms\Builder\FormSchema;
use Gadya\Cms\Forms\SubmissionContext;
use Illuminate\Support\Str;

/**
 * Reads a destination's mapping - which of its attributes is filled from
 * what - and suggests one for a form.
 *
 * Each value in a mapping is one of three things:
 *
 * - the key of a question on the form (`'email'`): its answer;
 * - a token (`'@utm_source'`, `'@consent.at'`): see SubmissionContext;
 * - anything else: that value, as written (`'new'`). Start it with `=`
 *   to keep it as written even where a question has that key (`'=email'`).
 *
 * A question the visitor did not answer, or a token with nothing behind
 * it, gives null - never the words of the key.
 */
final class DestinationMap
{
    /**
     * @param  array<string, mixed>  $map  attribute => source
     * @param  array<string, mixed>  $data  The answers
     * @param  list<string>  $keys  Every question on the form, answered or not
     * @return array<string, mixed>
     */
    public static function resolve(array $map, array $data, SubmissionContext $context, array $keys = []): array
    {
        $resolved = [];

        foreach ($map as $attribute => $source) {
            if (! is_string($attribute) || $attribute === '') {
                continue;
            }

            $resolved[$attribute] = self::value($source, $data, $context, $keys);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    public static function value(mixed $source, array $data, SubmissionContext $context, array $keys = []): mixed
    {
        if (! is_string($source)) {
            return $source;
        }

        $source = trim($source);

        return match (true) {
            $source === '' => null,
            str_starts_with($source, '=') => substr($source, 1),
            str_starts_with($source, '@') => $context->token($source),
            array_key_exists($source, $data) => $data[$source],
            in_array($source, $keys, true) => null,
            default => $source,
        };
    }

    /**
     * The mapping for one form: the destination's own, with the client's
     * changes for this form over it. A blank in the client's mapping
     * leaves that attribute out.
     *
     * @param  array<string, mixed>  $configured
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    public static function merged(array $configured, ?array $override): array
    {
        $map = $configured;

        foreach ((array) $override as $attribute => $source) {
            if (! is_string($attribute) || $attribute === '') {
                continue;
            }

            if ($source === null || (is_string($source) && trim($source) === '')) {
                unset($map[$attribute]);

                continue;
            }

            $map[$attribute] = $source;
        }

        return $map;
    }

    /**
     * A mapping worked out from the destination's attributes and the
     * form's questions: a question with the same name (or a common other
     * name for it), a question of the right kind, or the token an
     * attribute's name points at. What the destination already maps in
     * config is kept.
     *
     * @param  array<string, mixed>  $configured
     * @return array<string, string>
     */
    public static function suggest(array $attributes, FormSchema $schema, array $configured = []): array
    {
        $fields = collect($schema->inputs());
        $suggested = [];

        foreach ($attributes as $attribute) {
            if (! is_string($attribute) || $attribute === '') {
                continue;
            }

            if (is_string($configured[$attribute] ?? null)) {
                $suggested[$attribute] = $configured[$attribute];

                continue;
            }

            $plain = self::plain($attribute);
            $sameName = $fields->first(fn (array $field): bool => in_array(self::plain($field['key']), self::synonyms($plain), true));
            $token = self::tokenFor($attribute);
            $type = self::typeFor($plain);
            $ofType = $type === null ? null : $fields->first(fn (array $field): bool => $field['type'] === $type);

            $source = match (true) {
                $sameName !== null => $sameName['key'],
                $token !== null => $token,
                $ofType !== null => $ofType['key'],
                default => null,
            };

            if ($source !== null) {
                $suggested[$attribute] = $source;
            }
        }

        return $suggested;
    }

    /** The token an attribute's name most likely wants, if any. */
    public static function tokenFor(string $attribute): ?string
    {
        $name = Str::snake($attribute);

        return match (true) {
            (bool) preg_match('/^utm_(source|medium|campaign|term|content)$/', $name, $match) => '@utm_'.$match[1],
            in_array($name, ['gclid', 'fbclid', 'msclkid'], true) => '@'.$name,
            (bool) preg_match('/(consent|agree|accept).*(at|date|time|on)$|^consented/', $name) => '@consent.at',
            (bool) preg_match('/(policy|privacy).*(version|revision)/', $name) => '@consent.policy_version',
            (bool) preg_match('/(policy|privacy).*url/', $name) => '@consent.policy_url',
            (bool) preg_match('/consent.*(text|wording)/', $name) => '@consent.text',
            (bool) preg_match('/consent.*ip|ip.*consent/', $name) => '@consent.ip',
            in_array($name, ['consent', 'consented', 'has_consent', 'agreed', 'gdpr'], true) => '@consent.given',
            (bool) preg_match('/landing/', $name) => '@landing_page',
            (bool) preg_match('/referr?er/', $name) => '@referrer',
            (bool) preg_match('/^(utm_)?(source|channel|lead_source)$/', $name) => '@source',
            (bool) preg_match('/^(medium|campaign)$/', $name, $match) => '@utm_'.$match[1],
            (bool) preg_match('/^(page|page_url|url|source_url|form_url|sent_from)$/', $name) => '@page_url',
            (bool) preg_match('/^(locale|language|lang)$/', $name) => '@locale',
            (bool) preg_match('/^(brand|site|site_key|domain|website)$/', $name) => '@site',
            (bool) preg_match('/^(ip|ip_address|remote_ip)$/', $name) => '@ip',
            (bool) preg_match('/user_?agent/', $name) => '@user_agent',
            default => null,
        };
    }

    private static function typeFor(string $plain): ?string
    {
        return match (true) {
            in_array($plain, ['email', 'emailaddress', 'mail'], true) => 'email',
            in_array($plain, ['phone', 'telephone', 'tel', 'mobile', 'phonenumber', 'cell'], true) => 'phone',
            in_array($plain, ['name', 'fullname', 'clientname', 'customername'], true) => 'name',
            in_array($plain, ['message', 'comment', 'comments', 'body', 'enquiry', 'inquiry', 'details', 'text'], true) => 'long_text',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private static function synonyms(string $plain): array
    {
        foreach ([
            ['name', 'fullname', 'yourname'],
            ['email', 'emailaddress', 'mail', 'youremail'],
            ['phone', 'telephone', 'tel', 'mobile', 'phonenumber', 'cell'],
            ['message', 'comment', 'comments', 'body', 'enquiry', 'inquiry', 'details', 'text'],
            ['company', 'business', 'organisation', 'organization'],
        ] as $group) {
            if (in_array($plain, $group, true)) {
                return $group;
            }
        }

        return [$plain];
    }

    private static function plain(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }
}
