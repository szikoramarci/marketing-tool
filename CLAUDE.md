# Funnel rendszer – online konzultációs praxis

Kampányalapú értékesítési rendszer egy 1:1 online konzultációs praxishoz. A látogató egy kampány kvízét tölti ki (4–5 kérdés), a válaszai alapján szegmensbe kerül, és személyre szabott visszajelzést, valamint a szegmenséhez tartozó videót kapja. Innen a bizalmi szintjéhez illő következő lépés jön: további tartalmak (nurture), csomagajánlat vagy ingyenes 30–60 perces első beszélgetés. A cél egy 4–5 alkalmas 1:1 kurzuscsomag megvásárlása, erőszakos sürgetés nélkül. A praxist vezető szakember nem technikai felhasználó: az admin felületen napi teendőlistát lát.

## Tech stack

- Laravel (legfrissebb stabil), PHP 8.4, PostgreSQL
- Publikus felület: Blade + Tailwind CSS + Alpine.js, szerveroldali renderelés
- Admin felület: Filament, `/admin` alatt
- Állapotgép: `spatie/laravel-model-states`
- Webhookok: `spatie/laravel-webhook-client`
- Queue: `database` driver (Postgres), nincs Redis
- Tesztek: Pest
- Fejlesztői környezet: Laravel Sail (Docker) WSL2 alatt, Mailpit a levelekhez
- Laravel Boost MCP: verzióspecifikus API (Laravel, Filament, Pest) használata előtt keress vele a dokumentációban

## Parancsok

Minden parancs a Sail konténerben fut. Mindig a teljes útvonalat használd (`./vendor/bin/sail`), mert a `sail` alias nem biztos, hogy létezik a shelledben.

```bash
./vendor/bin/sail up -d                                # környezet indítása
./vendor/bin/sail artisan migrate:fresh --seed         # adatbázis újraépítése demóadatokkal
./vendor/bin/sail artisan test --parallel              # teljes tesztcsomag
./vendor/bin/sail artisan test --filter=OfferFollowUp  # egy teszt vagy fájl
./vendor/bin/sail pint --dirty                         # módosított fájlok formázása
./vendor/bin/sail npm run dev                          # Vite dev szerver
./vendor/bin/sail artisan funnel:process-deadlines     # határidők feldolgozása kézzel
```

Címek: alkalmazás http://localhost, admin http://localhost/admin, Mailpit http://localhost:8025

## Architektúra

Monolit, klasszikus MVC.

- `routes/web.php`: publikus oldalak (landing, kvíz, eredmény, email-megadás, leiratkozás)
- `/admin`: Filament panel
- `routes/api.php`: külső szolgáltatók webhookjai `/api/webhooks/{provider}` alatt

### Domain

- `Campaign`: központi entitás (téma, landing szövegek, kvíz, szegmensek, csomag, utánkövetési szabályok). Minden kampányonként konfigurálható, semmi nincs kódba égetve.
- `Quiz`: verziózott. Publikált kvíz módosítása új verziót hoz létre; a kitöltések a verzióra hivatkoznak, hogy a régiek értelmezhetők maradjanak.
- `Question`, `AnswerOption`: minden válaszopció szegmensenkénti pontszámot és egy előre megírt, rövid visszajelző mondatot tartalmaz. A kiértékelés determinisztikus: pontösszeg → szegmens.
- `Segment`: visszajelző szöveg, videó, ajánlott csomag.
- `Package`: ár, alkalmak száma és hossza, garancia. Több kampány is használhatja.
- `QuizSubmission`: egy kitöltés válaszai és eredménye.
- `Lead`: a kitöltő. Email nélkül is létrejön; ő az állapotgép tárgya.
- `LeadEvent`: csak hozzáfűzhető eseménynapló (ki, mikor, miből mibe, milyen okból, metaadat). Ebből jön a funnel-statisztika.
- `FollowUpRule`: kampányonkénti időszabály, pl. „`OfferSent` után 3 nap → email teendő, 7 nap → hívás teendő”.
- `Task`: a szakember teendője (típus, esedékesség, lead, lezárás).

### Lead állapotgép

| Állapot | Jelentés | Megengedett átmenetek |
|---|---|---|
| `Anonymous` | kvíz kitöltve, nincs email | `Nurture`, `OfferSent`, `CallBooked` |
| `Nurture` | van email, tartalmakat kap | `OfferSent`, `CallBooked`, `Unsubscribed` |
| `OfferSent` | csomagajánlat elküldve | `CallBooked`, `Purchased`, `Nurture`, `Unsubscribed` |
| `CallBooked` | ingyenes hívás lefoglalva | `CallCompleted`, `NoShow`, `Unsubscribed` |
| `NoShow` | nem jelent meg a hívásra | `CallBooked`, `Nurture`, `Unsubscribed` |
| `CallCompleted` | hívás megtörtént, döntési határidő fut | `Purchased`, `Considering`, `Nurture`, `Unsubscribed` |
| `Considering` | gondolkodik | `Purchased`, `Nurture`, `Unsubscribed` |
| `Purchased` | vásárolt, kurzus folyamatban | `Completed`, `RefundRequested` |
| `RefundRequested` | visszatérítést kért | `Refunded`, `Purchased` |
| `Refunded` | visszatérítve | végállapot |
| `Completed` | kurzus befejezve (vélemény, ajánlás) | végállapot |
| `Unsubscribed` | leiratkozott | végállapot, semmilyen levél nem mehet |

Szabályok:

- Állapotot csak átmeneten keresztül válts (`$lead->state->transitionTo(...)`), a `state` oszlopot soha ne írd közvetlenül.
- Minden átmenet ugyanabban a DB-tranzakcióban `LeadEvent`-et ír.
- Az állapot azt mondja meg, hol tart a lead. A teendőket nem az állapot, hanem a `FollowUpRule`-ok hozzák létre időalapon.
- Vásárlás után a marketing-hozzájárulás visszavonása nem állapotváltás, hanem a `marketing_consent_withdrawn_at` mező; a kurzushoz tartozó tranzakciós levelek továbbra is mennek.
- A táblázat kiinduló változat. A mérvadó leírás a `tests/Feature/StateMachine/LeadTransitionsTest.php`; ha a kettő eltér, a teszt a hiteles, és jelezd az eltérést.

### Időzített logika

- A `funnel:process-deadlines` parancs az ütemezőben 5 percenként fut, és a lejárt `FollowUpRule`-ok alapján teendőt hoz létre. Idempotens: ugyanarra a szabályra és leadre kétszer nem hoz létre teendőt.
- Teendő lezárása esemény, ami átmenetet is kiválthat (pl. hívás teendő lezárása → `CallCompleted`).
- Hosszú várakozásra ne használj késleltetett jobot. Az időalapú logika mindig az adatbázisban tárolt időpontokból számol, így tesztben időutazással ellenőrizhető.

### Külső szolgáltatások

- Minden szolgáltatás interfész mögött van: `app/Contracts` (pl. `PaymentGateway`, `InvoiceProvider`, `AdsPlatform`, `FeedbackGenerator`), implementáció az `app/Services/{Terület}/` alatt.
- Minden interfésznek van `Fake…` implementációja, a driver a configból jön (pl. `PAYMENT_DRIVER=fake`). Lokálisan és tesztben a fake az alapértelmezett.
- Controller, Filament resource és job csak az interfészt kapja (DI), SDK-t közvetlenül nem hív.
- Webhook: a nyers payload mentése (`spatie/laravel-webhook-client`), feldolgozás queue jobban, idempotensen (a szolgáltató eseményazonosítója egyedi).
- Időpontfoglalás: Cal.com; a foglalás webhookja váltja a lead állapotát `CallBooked`-ra.

## Frontend

- Publikus felület: ez a „szép” felület, egyedi design Tailwinddel, mobile-first, gyors betöltéssel. JavaScript csak ahol kell (a kvíz léptetése Alpine-nal). Hirdetési és analitikai script csak süti-hozzájárulás után töltődhet be.
- Admin (Filament): a kezdőoldal a napi teendőlista (lejárt és mai teendők), egy kattintással lezárható teendőkkel. Egyszerű, magyar nyelvű felület, kevés mező, érthető címkék.

## Konvenciók

- A felület nyelve magyar (fordítások a `lang/hu` alatt); kód, azonosítók, kommentek és commit üzenetek angolul.
- Pénzösszeg egész számként, forintban, soha nem float.
- Adatbázisban UTC; megjelenítés és határidő-számítás `Europe/Budapest` szerint.
- `env()` csak a `config/` fájlokban.
- Új composer vagy npm csomagot csak egyeztetés után vegyél fel.
- Kis, egy témájú változtatások; commit csak zöld tesztekkel.

## Adatvédelem

- A kvízválaszok különleges kategóriájú (pl. egészségügyi) adatnak minősülhetnek. Kvízválasz és szegmens soha nem mehet hirdetési platformra (Meta Pixel, Conversions API); oda csak semleges konverziós esemény mehet (pl. „kvíz kitöltve”, „email megadva”).
- Marketing levél csak double opt-in hozzájárulás után, minden marketing levélben leiratkozó linkkel.
- Személyes adat nem kerülhet logba.
- Törlési kérelemnél a lead anonimizálódik; az események személyes adat nélkül megmaradnak a statisztikához.
- AI szolgáltatónak személyes adat vagy kvízválasz csak kifejezett döntés után mehet (lásd: Nyitott döntések).

## Tesztelés

Két teszttípus védi a rendszert:

1. **Forgatókönyv-tesztek** (`tests/Feature/Scenarios/`): a funnel egy-egy útvonala HTTP-n keresztül, valódi Postgresen, fake külső szolgáltatásokkal és időutazással. Kívülről írják le a viselkedést, a belső felépítést nem ismerik.
2. **Állapotgép-teszt** (`tests/Feature/StateMachine/LeadTransitionsTest.php`): Pest dataset az összes megengedett és tiltott átmenettel.

Unit tesztet csak kérésre írj.

### Szabályok

- Meglévő tesztet ebben a két könyvtárban NEM módosíthatsz és nem törölhetsz azért, hogy átmenjen. Ha egy változtatás miatt szükséges, állj meg, és magyarázd el, mi és miért változna. Ez shellen keresztül is érvényes: ne kerüld meg `sed`-del vagy fájlírással.
- Új funkciónál: először a forgatókönyv-tesztet írd meg és futtasd (el kell buknia), mutasd meg, és csak jóváhagyás után implementálj.
- Egy feladat akkor kész, ha a `./vendor/bin/sail pint --dirty` lefutott, és a `./vendor/bin/sail artisan test --parallel` zöld.
- A CI (GitHub Actions) ugyanezt futtatja Postgres service-szel; a `main` ágra csak zöld PR kerül.

### Gyorsaság

- Iteráció közben csak az érintett tesztet futtasd (`--filter`), a teljes csomagot a végén, párhuzamosan.
- Hálózat tilos: a `tests/Pest.php` globálisan hívja a `Http::preventStrayRequests()`-et. A külső válaszok a `tests/Fixtures/{provider}/*.json` fájlokból jönnek (sandboxból mentett valódi payloadok).
- Idő: `$this->travel()` és `$this->freezeTime()`, soha `sleep`.
- Tesztben nincs frontend build: a base `TestCase` `withoutVite()`-ot hív.
- A `phpunit.xml`-ben: `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, cache és session `array`.
- `RefreshDatabase` tranzakcióval; ha a migrációk száma megnő, `schema:dump`.
- Tesztadat factory-kkal, olvasható state-ekkel (pl. `Lead::factory()->inState(OfferSent::class)`); közös tesztsegédek a `tests/Support/` alatt (pl. `QuizAnswers::forSegment('B')`).

## Lokális fejlesztés

- Natív Windows + Docker Desktop (nincs Ubuntu WSL-disztribúció telepítve). A repó Windows natív útvonalon van (`D:\Projects\marketing-tool`), bind mountolva a Sail-konténerekbe. A `vendor/laravel/sail/bin/sail` szkript alapból csak macOS/Linuxot (WSL2-t) ismer fel; a `composer.json` `post-autoload-dump` szkriptje a `scripts/patch-sail-for-windows.php`-n keresztül minden telepítés után automatikusan foltozza, hogy Git Bash/MSYS alól is fusson.
- Bind mountolt Windows könyvtárban új fájlok/mappák néha root-tulajdonúként jönnek létre a konténerben, amit a `sail` felhasználó nem tud írni (pl. `storage/`, `bootstrap/cache/`). Ha ilyen jogosultsági hibát (`Permission denied`) látsz, futtasd: `./vendor/bin/sail root-shell -c "chown -R sail:sail /var/www/html"`.
- A lokális Postgres a `compose.yaml`-ban `fsync=off`, `synchronous_commit=off`, `full_page_writes=off` kapcsolókkal fut (csak fejlesztéshez).
- `migrate:fresh --seed`: demó kampány kvízzel és szegmensekkel, minden állapotban legalább egy leaddel, valamint admin felhasználó (`admin@example.com` / `password`).
- A `.env.example`-ben minden külső szolgáltatás `fake` driverrel szerepel. Valódi sandbox kulcsok csak a `.env`-ben vannak; azt ne olvasd, új kulcsot a `.env.example`-be vegyél fel.
- Csak lokális környezetben elérhető: `funnel:shift-time {lead} {--days=}`, ami a lead időpontjait eltolja, hogy a határidős folyamatok kézzel is kipróbálhatók legyenek.

## Nyitott döntések – ezekben ne dönts helyettünk, kérdezz

- A praxis szakterülete (befolyásolja a GDPR-kategóriát és a hirdetési szabályokat)
- Fizetés: Barion, SimplePay vagy Stripe
- Számlázás: Számlázz.hu vagy Billingo
- Emailküldő (Postmark, Resend, Brevo), és hogy a sorozatokat mi építjük, vagy Brevo/MailerLite kezeli
- Személyre szabott visszajelzés: kezdetben szabályalapú; AI csak később, ellenőrzött kimenettel
- Hosting

## Első mérföldkő

Kampány + kvíz + eredményoldal (szegmens-visszajelzés, videó) + email-megadás double opt-innel + Lead állapotgéppel és eseménynaplóval + Filament lead-lista és napi teendőlista + Cal.com foglalási link. Fizetés, számlázás és hirdetés-API később jön.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `vendor/bin/sail npm run build`, `vendor/bin/sail npm run dev`, or `vendor/bin/sail composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `vendor/bin/sail artisan route:list`). Use `vendor/bin/sail artisan list` to discover available commands and `vendor/bin/sail artisan [command] --help` to check parameters.
- Inspect routes with `vendor/bin/sail artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `vendor/bin/sail artisan config:show app.name`, `vendor/bin/sail artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `vendor/bin/sail artisan list` and check their parameters with `vendor/bin/sail artisan [command] --help`.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `vendor/bin/sail artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `vendor/bin/sail artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `vendor/bin/sail artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/sail bin phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
