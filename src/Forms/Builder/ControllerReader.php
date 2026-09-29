<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Forms\Destinations\DestinationMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * Reads the controller a site's own form posts to, and says what it does
 * beyond keeping the enquiry and emailing it - so the form can be handed
 * to the client without losing any of it.
 *
 * The method is read, never run. It looks for the records it creates or
 * updates (and with what), the events it fires, where it sends the
 * visitor and with which message, whether it deals with consent or a
 * privacy policy, whether it reads the ad campaign, and anything no
 * destination can do - a payment, a call to another service. From the
 * record it creates it suggests a destination for config, mapping each
 * attribute to a question, a token or a value. The person converting it
 * checks the suggestion: code this reader cannot follow is reported, not
 * guessed at.
 */
class ControllerReader
{
    /** Facades and helpers whose static calls are not records being made. */
    private const NOT_MODELS = ['Mail', 'Log', 'Event', 'Http', 'Notification', 'Validator', 'Redirect', 'Session', 'Cache', 'DB', 'Storage', 'Str', 'Arr', 'Carbon', 'Auth', 'Route', 'URL', 'App', 'Lang', 'Cookie', 'Queue', 'Bus', 'Gate', 'Hash', 'Crypt', 'Config', 'Request', 'Response', 'View', 'Date', 'Blade', 'Artisan', 'Schema', 'File', 'Password', 'RateLimiter', 'Process', 'Pipeline', 'Context', 'Concurrency', 'Number', 'Uri', 'Js', 'Vite', 'Collection', 'self', 'static', 'parent'];

    /** Signs of work no mapping can describe. */
    private const CUSTOM = [
        '/\bHttp::\w+/' => 'calls another service over HTTP (Http::)',
        '/\bnew\s+\\\\?(GuzzleHttp\\\\)?Client\s*\(/' => 'calls another service over HTTP (Guzzle)',
        '/\bcurl_\w+\(/' => 'calls another service with curl',
        '/\bSoapClient\b/' => 'calls a SOAP service',
        '/\bStripe\b|\bCashier\b|->charge\(|->checkout\(|\bPaddle\b|\bPayPal\b/i' => 'takes a payment',
        '/\bMailchimp\b|\bNewsletter::|\bHubspot\b|\bSalesforce\b|\bPipedrive\b|\bZoho\b/i' => 'sends the enquiry to a third-party service',
        '/\bTwilio\b|\bVonage\b|\bNexmo\b/i' => 'sends a text message through its own code',
    ];

    public function __construct(
        private readonly Filesystem $files,
        private readonly Router $router,
        private readonly RoutesFile $routesFile,
        private readonly TemplateValues $values,
    ) {}

    /**
     * The controller action a form's `action` leads to: through the running
     * router first (by the route's name, which also finds a route whose
     * address is worked out in code), then by reading `routes/*.php`. An
     * invokable controller - `ContactController::class`, a class name in a
     * string, `[ContactController::class]` - is its `__invoke`.
     *
     * @param  array{route?: string, controller?: string, url?: string}  $action
     * @return array{route: string|null, action: string, class: string, method: string, file: string|null, found_by?: string}|null
     */
    public function resolve(array $action, ?string $app = null): ?array
    {
        $route = null;
        $name = null;
        $foundBy = 'router';

        if (isset($action['route'])) {
            $routes = $this->router->getRoutes();

            if (method_exists($routes, 'refreshNameLookups')) {
                $routes->refreshNameLookups();
            }

            $route = $routes->getByName($action['route']);
            $name = $action['route'];
        } elseif (isset($action['url'])) {
            $route = $this->routeForUrl($action['url']);
            $name = $route?->getName();
        }

        $handler = $route !== null ? $this->handlerOf($route) : ($action['controller'] ?? null);

        /* Not in the router (a route registered only in some requests, say): read the routes files. */
        if ($handler === null && $name !== null) {
            $read = $this->routesFile->find($name, rtrim(dirname($app ?? app_path()), '/').'/routes');

            if ($read !== null) {
                $handler = $read['class'].'@'.$read['method'];
                $foundBy = 'routes file ('.self::tidy($read['file']).':'.$read['line'].')';
            }
        }

        if (! is_string($handler) || $handler === '' || $handler === 'Closure') {
            return null;
        }

        if (! str_contains($handler, '@')) {
            $handler .= '@__invoke';
        }

        [$class, $method] = explode('@', ltrim($handler, '\\'), 2);

        if (! str_contains($class, '\\')) {
            $class = 'App\\Http\\Controllers\\'.$class;
        }

        return [
            'route' => $name,
            'action' => $class.'@'.$method,
            'class' => $class,
            'method' => $method,
            'file' => $this->fileOf($class, $app),
            'found_by' => $foundBy,
        ];
    }

    /**
     * A route's controller action as `Class@method`; null for a closure.
     * Laravel keeps an invokable controller's action as the class alone.
     */
    private function handlerOf(Route $route): ?string
    {
        $uses = $route->getAction('uses');
        $controller = $route->getAction('controller');

        if (is_string($uses) && str_contains($uses, '@')) {
            return $uses;
        }

        if (is_string($controller) && $controller !== '') {
            return str_contains($controller, '@') ? $controller : $controller.'@__invoke';
        }

        return is_string($uses) && $uses !== '' ? $uses.'@__invoke' : null;
    }

    /**
     * The fields named in `$request->only([...])` or `->safe()->only(...)`.
     *
     * @return list<string>|null
     */
    private function only(string $argument): ?array
    {
        if (preg_match('/only\(\s*\[?([^\])]*)/', $argument, $match) !== 1) {
            return null;
        }

        preg_match_all('/[\'"]([\w.-]+)[\'"]/', $match[1], $names);

        return $names[1] === [] ? null : $names[1];
    }

    /**
     * What one controller method does.
     *
     * @param  list<string>  $fields  The questions the form asks
     * @return array<string, mixed>
     */
    public function read(string $file, string $class, string $method, array $fields = [], ?string $route = null): array
    {
        $source = (string) $this->files->get($file);
        [$body, $line] = PhpSource::method($source, $method);

        if ($body === null) {
            return ['file' => $file, 'line' => 0, 'found' => false, 'does' => ["The method {$method}() was not found in the file; read it by hand."], 'custom' => []];
        }

        $uses = PhpSource::uses($source);
        $writes = $this->writes($body, $uses, $source);
        $events = $this->events($body, $uses);
        $redirects = $this->redirects($body);
        $redirect = $this->chooseRedirect($redirects, $route);
        $redirect = $redirect === null ? null : array_diff_key($redirect, ['condition' => true]);
        $recipients = $this->recipients($body, $uses);
        $analytics = preg_match('/[\'"](?:analytics_event|analytics|gtm_event|ga_event|tracking_event)[\'"]\s*(?:,|=>)\s*[\'"]([\w.:-]+)[\'"]/', $body, $event) === 1 ? $event[1] : null;
        $custom = [];

        foreach (self::CUSTOM as $pattern => $description) {
            if (preg_match($pattern, $body) === 1) {
                $custom[] = $description;
            }
        }

        $consent = preg_match('/consent|privacy|agree|gdpr|policy/i', $body) === 1;
        $attribution = preg_match('/utm_|gclid|fbclid|msclkid|referr?er|landing/i', $body) === 1;
        $emails = preg_match('/\bMail::|\bNotification::|->notify(Now)?\(/', $body) === 1;
        $policyConfig = preg_match('/config\(\s*[\'"]([^\'"]*(?:privacy|policy)[^\'"]*)[\'"]/i', $body, $match) === 1 ? $match[1] : null;

        $does = [];

        foreach ($writes as $write) {
            $does[] = ucfirst($write['method'] === 'update' ? 'updates' : 'creates').' a '.class_basename($write['model']).' ('.$write['model'].')';
        }

        foreach ($events as $event) {
            $does[] = 'Fires '.$event.' - listen for Gadya\\Cms\\Events\\FormSubmitted (or the record\'s created event) to fire it from there.';
        }

        if ($redirect !== null) {
            $does[] = 'Sends the visitor '.($redirect['route'] ?? $redirect['url'] ?? 'back').(isset($redirect['message_key']) ? ' with the message '.$redirect['message_key'] : (isset($redirect['message']) ? ' with "'.$redirect['message'].'"' : ''));
        }

        if ($consent) {
            $does[] = 'Deals with consent or the privacy policy'.($policyConfig !== null ? ' (the version from config '.$policyConfig.')' : '');
        }

        if ($attribution) {
            $does[] = 'Keeps where the visitor came from (campaign, referrer or landing page)';
        }

        if ($emails) {
            $does[] = 'Sends an email'.($recipients['emails'] !== [] ? ' to '.implode(', ', $recipients['emails']).' (from '.implode(', ', $recipients['from']).')' : '').' - the builder form\'s own staff emails do this'.($recipients['emails'] !== [] ? ', and are set to the same addresses' : '');
        }

        foreach ($recipients['unresolved'] as $expression) {
            $does[] = 'Emails '.$expression.', which is not set in this environment (or is worked out per enquiry): set the form\'s staff emails by hand.';
        }

        foreach ($custom as $description) {
            $does[] = 'It '.$description.' - no destination in config can do that.';
        }

        return [
            'file' => $file,
            'line' => $line,
            'found' => true,
            'writes' => $writes,
            'events' => $events,
            'redirect' => $redirect,
            'redirects' => count($redirects) > 1 ? $redirects : [],
            'notify' => $recipients,
            'analytics_event' => $analytics,
            'consent' => $consent,
            'policy_version_config' => $policyConfig,
            'attribution' => $attribution,
            'emails' => $emails,
            'custom' => $custom,
            'beyond_storing' => $writes !== [] || $events !== [] || $custom !== [] || $consent || $attribution,
            'does' => $does,
            'lang_keys' => LangFiles::keysIn($body),
        ];
    }

    /**
     * A destination for config that does what the controller's record
     * does: its model, each attribute mapped, and the fixed values as
     * defaults.
     *
     * @param  array<string, mixed>  $analysis  From read()
     * @param  list<string>  $fields  The questions the form asks
     * @return array{key: string, config: array<string, mixed>, unmapped: array<string, string>}|null
     */
    public function suggest(array $analysis, array $fields): ?array
    {
        $write = collect($analysis['writes'] ?? [])->first(fn (array $write): bool => $write['method'] !== 'update') ?? ($analysis['writes'][0] ?? null);

        if (! is_array($write)) {
            return null;
        }

        $map = [];
        $defaults = [];
        $unmapped = [];

        if ($write['attributes'] === null) {
            /* Handed the whole of what was sent: each question by its own name. */
            foreach ($write['only'] ?? $fields as $field) {
                $map[$field] = $field;
            }
        }

        foreach ($write['attributes'] ?? [] as $attribute => $expression) {
            $literal = $this->literal($expression);

            if ($literal['is']) {
                if ($literal['value'] !== null) {
                    $defaults[$attribute] = $literal['value'];
                }

                continue;
            }

            $source = $this->sourceOf($attribute, $expression, $fields);

            if ($source !== null) {
                $map[$attribute] = $source;
            } else {
                $unmapped[$attribute] = $expression;
            }
        }

        if (($analysis['consent'] ?? false) && ! in_array('@consent.at', $map, true)) {
            foreach (['consented_at', 'consent_at', 'agreed_at'] as $attribute) {
                if (isset($unmapped[$attribute])) {
                    $map[$attribute] = '@consent.at';
                    unset($unmapped[$attribute]);
                }
            }
        }

        $key = Str::plural(Str::snake(class_basename($write['model'])));

        return [
            'key' => $key,
            'config' => [
                'label' => Str::headline($key),
                'model' => $write['model'],
                'map' => $map,
                'defaults' => $defaults,
            ],
            'unmapped' => $unmapped,
        ];
    }

    /**
     * What a controller's expression for one attribute becomes: a question,
     * a token, or nothing this reader can tell.
     *
     * @param  list<string>  $fields
     */
    public function sourceOf(string $attribute, string $expression, array $fields): ?string
    {
        $input = null;

        foreach ([
            '/\$request->(?:input|get|post|string|str|integer|boolean|date)\(\s*[\'"]([\w.]+)[\'"]/',
            '/\brequest\(\s*[\'"]([\w.]+)[\'"]/',
            '/\$request\[\s*[\'"]([\w.]+)[\'"]\s*\]/',
            '/\$(?:validated|data|input|attributes|values|payload)\[\s*[\'"]([\w.]+)[\'"]\s*\]/',
            '/\$request->(?!validated|input|get|post|ip|userAgent|header|headers|url|fullUrl|query|cookie|session|user|route|boolean|string|integer|date|only|all|except|has|filled|path|decodedPath|is|method|root|segments?|getHost|host|httpHost|getPathInfo|server)(\w+)\b/',
        ] as $pattern) {
            if (preg_match($pattern, $expression, $match) === 1) {
                $input = $match[1];

                break;
            }
        }

        $name = Str::snake($attribute);

        $moment = preg_match('/\bnow\(\)|Carbon::now|Date::now/', $expression) === 1;

        return match (true) {
            /* Several campaign parameters kept together, as `array_filter(['utm_source' => ..., 'utm_medium' => ...])`. */
            preg_match_all('/utm_(source|medium|campaign|term|content)|gclid|fbclid|msclkid/', $expression) > 1 || ($name === 'attribution' && preg_match('/utm_|referr?er|landing/', $expression) === 1) => '@attribution',
            $input !== null && preg_match('/^(utm_(source|medium|campaign|term|content)|gclid|fbclid|msclkid)$/', $input) === 1 => '@'.$input,
            $moment && preg_match('/consent|agree|accept|privacy/', $name) === 1 => '@consent.at',
            (bool) preg_match('/config\(\s*[\'"][^\'"]*(privacy|policy)[^\'"]*[\'"]/i', $expression) => '@consent.policy_version',
            $input !== null && preg_match('/consent|agree|privacy|gdpr/i', $input) === 1 => in_array($input, $fields, true) ? $input : '@consent.given',
            $input !== null && in_array($input, $fields, true) => $input,
            (bool) preg_match('/utm_(source|medium|campaign|term|content)/', $expression, $match) => '@utm_'.$match[1],
            (bool) preg_match('/\b(gclid|fbclid|msclkid)\b/', $expression, $match) => '@'.$match[1],
            (bool) preg_match('/landing/i', $expression) => '@landing_page',
            (bool) preg_match('/referr?er/i', $expression) && preg_match('/header|headers/', $expression) === 1 => '@page_url',
            (bool) preg_match('/referr?er/i', $expression) => '@referrer',
            (bool) preg_match('/url\(\)->(previous|current|full)|->fullUrl\(\)|->url\(\)|\$request->(path|decodedPath|getPathInfo)\(\)|request\(\)->(path|decodedPath)\(\)/', $expression) => '@page_url',
            (bool) preg_match('/->ip\(\)/', $expression) => '@ip',
            (bool) preg_match('/userAgent\(\)|User-Agent/i', $expression) => '@user_agent',
            (bool) preg_match('/getLocale\(\)|locale\(\)|->locale\b/', $expression) => '@locale',
            (bool) preg_match('/getHost\(\)|brand|site/i', $expression) => '@site',
            $moment => '@submitted_at',
            in_array($attribute, $fields, true) => $attribute,
            $input !== null => $input,
            default => DestinationMap::tokenFor($attribute),
        };
    }

    /**
     * @return array{is: bool, value: mixed}
     */
    private function literal(string $expression): array
    {
        $expression = trim($expression);

        return match (true) {
            preg_match('/^([\'"])((?:(?!\1).)*)\1$/s', $expression, $match) === 1 => ['is' => true, 'value' => $match[2]],
            is_numeric($expression) => ['is' => true, 'value' => $expression + 0],
            in_array(strtolower($expression), ['true', 'false'], true) => ['is' => true, 'value' => strtolower($expression) === 'true'],
            strtolower($expression) === 'null' => ['is' => true, 'value' => null],
            preg_match('/^\\\\?[\w\\\\]+::[A-Z_]+(->value)?$/', $expression) === 1 => ['is' => true, 'value' => $this->constant($expression)],
            default => ['is' => false, 'value' => null],
        };
    }

    /** An enum case or a class constant, as its value when it can be loaded, else as written. */
    private function constant(string $expression): mixed
    {
        $value = rescue(fn (): mixed => constant(Str::before($expression, '->value')), null, report: false);

        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            is_scalar($value) => $value,
            default => $expression,
        };
    }

    /**
     * The records the method creates or updates.
     *
     * @param  array<string, string>  $uses
     * @return list<array{model: string, method: string, attributes: array<string, string>|null, only?: list<string>}>
     */
    private function writes(string $body, array $uses, string $source): array
    {
        $writes = [];

        preg_match_all('/(\\\\?[A-Z][\w\\\\]*)::(?:query\(\)\s*->\s*)?(create|forceCreate|updateOrCreate|firstOrCreate|insert)\s*\(/', $body, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($calls as $call) {
            $only = null;
            $model = $this->modelClass($call[1][0], $uses, $source);

            if ($model === null) {
                continue;
            }

            $arguments = PhpSource::arguments($body, $call[0][1] + strlen($call[0][0]) - 1);
            $method = $call[2][0];
            $attributes = [];
            $whole = false;

            foreach ($method === 'updateOrCreate' || $method === 'firstOrCreate' ? $arguments : array_slice($arguments, 0, 1) as $argument) {
                $pairs = PhpSource::pairs($argument);

                if ($pairs === null) {
                    $whole = true;
                    $only = $this->only($argument);

                    continue;
                }

                $attributes = [...$attributes, ...$pairs];
            }

            $writes[] = array_filter([
                'model' => $model,
                'method' => $method,
                'attributes' => $whole && $attributes === [] ? null : $attributes,
                'only' => $only,
            ], fn ($value, $key): bool => $key === 'attributes' || $value !== null, ARRAY_FILTER_USE_BOTH);
        }

        /* `$lead = new Lead; $lead->name = ...; $lead->save();` */
        preg_match_all('/\$(\w+)\s*=\s*new\s+(\\\\?[A-Z][\w\\\\]*)\s*(?:\(\s*(\[.*?\])?\s*\))?\s*;/s', $body, $news, PREG_SET_ORDER);

        foreach ($news as $new) {
            $model = $this->modelClass($new[2], $uses, $source);

            if ($model === null || preg_match('/\$'.$new[1].'->(save|push)\(\)/', $body) !== 1) {
                continue;
            }

            $attributes = isset($new[3]) && $new[3] !== '' ? (PhpSource::pairs($new[3]) ?? []) : [];

            preg_match_all('/\$'.$new[1].'->(\w+)\s*=\s*([^;]+);/', $body, $sets, PREG_SET_ORDER);

            foreach ($sets as $set) {
                $attributes[$set[1]] = trim($set[2]);
            }

            if (preg_match('/\$'.$new[1].'->(?:fill|forceFill)\(\s*(\[.*?\])\s*\)/s', $body, $fill) === 1) {
                $attributes = [...$attributes, ...(PhpSource::pairs($fill[1]) ?? [])];
            }

            $writes[] = ['model' => $model, 'method' => 'save', 'attributes' => $attributes];
        }

        if (preg_match('/\$\w+->update\(\s*\[/', $body) === 1 && $writes === []) {
            $writes[] = ['model' => 'unknown', 'method' => 'update', 'attributes' => []];
        }

        return $writes;
    }

    /**
     * @param  array<string, string>  $uses
     * @return list<string>
     */
    private function events(string $body, array $uses): array
    {
        $events = [];

        preg_match_all('/(?:\bevent|Event::dispatch|broadcast)\(\s*new\s+(\\\\?[A-Z][\w\\\\]*)/', $body, $fired);
        preg_match_all('/(\\\\?[A-Z][\w\\\\]*)::dispatch(?:Sync|Now|If|Unless)?\(/', $body, $dispatched);

        foreach ([...$fired[1], ...$dispatched[1]] as $class) {
            if (in_array(ltrim($class, '\\'), ['Event', 'Bus', 'Queue'], true)) {
                continue;
            }

            $events[] = $uses[ltrim($class, '\\')] ?? ltrim($class, '\\');
        }

        return array_values(array_unique($events));
    }

    /**
     * Every place the method sends the visitor, with the message flashed
     * and the condition it is under (for a controller that serves two
     * brands, say, and thanks each in its own words).
     *
     * @return list<array{route?: string, url?: string, back?: bool, message?: string, message_key?: string, flash?: string, condition?: string}>
     */
    private function redirects(string $body): array
    {
        $candidates = [];
        $flashed = null;

        /* A message flashed before the return: session()->flash('status', ...), Session::flash(...). */
        if (preg_match('/(?:session\(\)|Session::|->session\(\))\s*(?:->\s*)?flash\(/', $body, $flash, PREG_OFFSET_CAPTURE) === 1) {
            $arguments = PhpSource::arguments($body, $flash[0][1] + strlen($flash[0][0]) - 1);
            $flashed = $this->message($arguments);
        }

        preg_match_all('/\breturn\b/', $body, $returns, PREG_OFFSET_CAPTURE);

        foreach ($returns[0] as [, $offset]) {
            $statement = $this->statement($body, $offset + 6);

            if (preg_match('/\bredirect\(|\bback\(\)|\bto_route\(|->with\(|Redirect::/', $statement) !== 1) {
                continue;
            }

            $redirect = [];

            if (preg_match('/(?:->route|\bto_route|Redirect::route|(?<![\w>:])\w*_route|::route)\(\s*[\'"]([^\'"]+)[\'"]/', $statement, $match) === 1) {
                $redirect['route'] = $match[1];
            } elseif (preg_match('/redirect\((?:\)\s*->\s*to\()?\s*[\'"]([^\'"]+)[\'"]/', $statement, $match) === 1) {
                $redirect['url'] = $match[1];
            } elseif (preg_match('/\bback\(\)|->back\(\)/', $statement) === 1) {
                $redirect['back'] = true;
            }

            if (preg_match('/->with\(/', $statement, $with, PREG_OFFSET_CAPTURE) === 1) {
                $redirect = [...$redirect, ...$this->message(PhpSource::arguments($statement, $with[0][1] + strlen($with[0][0]) - 1))];
            } elseif ($flashed !== null) {
                $redirect = [...$redirect, ...$flashed];
            }

            $condition = $this->condition($body, $offset);

            if ($condition !== null) {
                $redirect['condition'] = $condition;
            }

            if ($redirect !== [] && $redirect !== ['condition' => $condition]) {
                $candidates[] = $redirect;
            }
        }

        return $candidates;
    }

    /**
     * The redirect a form posting to `$route` meets: the one sending the
     * visitor back to the same part of the site (`avant.contact` for
     * `avant.contact.submit`), or whose condition names that part, else
     * the one taken when no condition holds.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function chooseRedirect(array $candidates, ?string $route): ?array
    {
        if ($candidates === []) {
            return null;
        }

        $segments = $route === null ? [] : array_values(array_filter(preg_split('/[.\/]/', $route) ?: [], fn (string $segment): bool => ! in_array($segment, ['submit', 'store', 'send', 'post', 'save'], true) && preg_match('/^(localized|[a-z]{2})$/', $segment) !== 1));
        $scored = [];

        foreach ($candidates as $index => $candidate) {
            $score = 0;
            $target = array_values(array_filter(preg_split('/[.\/]/', (string) ($candidate['route'] ?? $candidate['url'] ?? '')) ?: [], fn (string $segment): bool => $segment !== '' && preg_match('/^(localized|[a-z]{2})$/', $segment) !== 1));

            if ($segments !== [] && $target !== [] && $target[0] === $segments[0]) {
                $score += 2;
            }

            if (isset($candidate['condition'])) {
                preg_match_all('/[\'"]([^\'"]+)[\'"]/', (string) $candidate['condition'], $words);

                foreach ($words[1] as $word) {
                    if (in_array(trim($word, '/*'), $segments, true)) {
                        $score++;
                    }
                }
            } else {
                $score += 0.5;
            }

            $scored[] = [$score, $index];
        }

        usort($scored, fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        return $candidates[$scored[0][1]];
    }

    /**
     * `('status', __('contact.thanks'))` as the flash's name and its
     * message or lang key.
     *
     * @param  list<string>  $arguments
     * @return array{flash?: string, message?: string, message_key?: string}
     */
    private function message(array $arguments): array
    {
        if (count($arguments) < 2 || preg_match('/^([\'"])(\w+)\1$/', $arguments[0], $name) !== 1) {
            return [];
        }

        $message = ['flash' => $name[2]];
        $key = LangFiles::keyIn($arguments[1]);

        if ($key !== null) {
            $message['message_key'] = $key;
        } elseif (preg_match('/^\s*([\'"])((?:(?!\1).)*)\1\s*$/s', $arguments[1], $text) === 1) {
            $message['message'] = stripslashes($text[2]);
        }

        return $message;
    }

    /** A statement from an offset to its `;`, past strings and brackets. */
    private function statement(string $source, int $start): string
    {
        $depth = 0;

        for ($i = $start, $length = strlen($source); $i < $length; $i++) {
            $character = $source[$i];

            if ($character === '\'' || $character === '"') {
                $i = PhpSource::skipString($source, $i);
            } elseif (in_array($character, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($character, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($character === ';' && $depth <= 0) {
                break;
            }
        }

        return substr($source, $start, $i - $start);
    }

    /** The condition of the innermost `if` whose block holds an offset. */
    private function condition(string $body, int $offset): ?string
    {
        $condition = null;

        preg_match_all('/\b(?:if|elseif)\s*\(/', $body, $ifs, PREG_OFFSET_CAPTURE);

        foreach ($ifs[0] as [$text, $start]) {
            $open = $start + strlen($text) - 1;
            $close = PhpSource::closing($body, $open);
            $brace = $close === null ? false : strpos($body, '{', $close);

            if ($close === null || $brace === false || $start > $offset || trim(substr($body, $close + 1, $brace - $close - 1)) !== '') {
                continue;
            }

            $end = PhpSource::closing($body, $brace);

            if ($end !== null && $brace < $offset && $end > $offset) {
                $condition = trim(substr($body, $open + 1, $close - $open - 1));
            }
        }

        return $condition;
    }

    /**
     * Who the method emails - `Mail::to(...)`, `Notification::route('mail',
     * ...)` - with a recipient from config or env worked out in the
     * running app.
     *
     * @param  array<string, string>  $uses
     * @return array{emails: list<string>, from: list<string>, unresolved: list<string>}
     */
    private function recipients(string $body, array $uses): array
    {
        $emails = [];
        $from = [];
        $unresolved = [];

        preg_match_all('/\bMail::(?:to|cc|bcc)\(|\bNotification::route\(\s*[\'"]mail[\'"]\s*,/', $body, $calls, PREG_OFFSET_CAPTURE);

        foreach ($calls[0] as [$text, $offset]) {
            $open = str_starts_with($text, 'Mail::') ? $offset + strlen($text) - 1 : $offset + strpos($text, '(');
            $arguments = PhpSource::arguments($body, $open);
            $expression = $arguments[str_starts_with($text, 'Mail::') ? 0 : 1] ?? null;

            if ($expression === null) {
                continue;
            }

            $written = $expression;

            if (preg_match('/^\$(\w+)$/', $expression, $variable) === 1) {
                $expression = PhpSource::assignment($body, $variable[1], $offset) ?? $expression;
            }

            $value = $this->values->evaluate($expression, [], $uses);
            $found = [];

            foreach ((array) ($value['ok'] ? $value['value'] : []) as $one) {
                foreach (is_string($one) ? preg_split('/[,;\s]+/', $one) ?: [] : [] as $address) {
                    if (filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                        $found[] = $address;
                    }
                }
            }

            if ($found === []) {
                $unresolved[] = $written === $expression ? $written : $written.' = '.$expression;

                continue;
            }

            $emails = [...$emails, ...$found];
            $from[] = $expression;
        }

        return ['emails' => array_values(array_unique($emails)), 'from' => array_values(array_unique($from)), 'unresolved' => array_values(array_unique($unresolved))];
    }

    /**
     * @param  array<string, string>  $uses
     */
    private function modelClass(string $name, array $uses, string $source): ?string
    {
        $name = ltrim($name, '\\');

        if (in_array($name, self::NOT_MODELS, true) || in_array(class_basename($name), self::NOT_MODELS, true)) {
            return null;
        }

        $class = $uses[$name] ?? (str_contains($name, '\\') ? $name : PhpSource::namespaceOf($source).'\\'.$name);

        if (class_exists($class)) {
            return is_subclass_of($class, Model::class) ? $class : null;
        }

        /* A class this process cannot load is taken for a model when it lives with the models. */
        return str_contains($class, '\\Models\\') ? $class : null;
    }

    private function routeForUrl(string $url): ?Route
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (! in_array('POST', $route->methods(), true)) {
                continue;
            }

            $pattern = '#^'.preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote(trim($route->uri(), '/'), '#')).'$#';

            if (trim($route->uri(), '/') === $path || preg_match($pattern, $path) === 1) {
                return $route;
            }
        }

        return null;
    }

    private function fileOf(string $class, ?string $app): ?string
    {
        if (class_exists($class)) {
            return rescue(fn (): ?string => (new ReflectionClass($class))->getFileName() ?: null, null, report: false);
        }

        if (str_starts_with($class, 'App\\')) {
            $file = rtrim($app ?? app_path(), '/').'/'.str_replace('\\', '/', substr($class, 4)).'.php';

            return is_file($file) ? $file : null;
        }

        return null;
    }

    /**
     * Where a controller's form is reported: its file relative to the app.
     */
    public static function tidy(?string $file): ?string
    {
        if ($file === null) {
            return null;
        }

        try {
            return Str::startsWith($file, base_path().'/') ? Str::after($file, base_path().'/') : $file;
        } catch (Throwable) {
            return $file;
        }
    }
}
