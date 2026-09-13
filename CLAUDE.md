# Funnel rendszer – online konzultációs praxis

Kampányalapú értékesítési rendszer egy 1:1 online konzultációs praxishoz. A látogató egy kampány kvízét tölti ki (4–5 kérdés), a válaszai alapján szegmensbe (a kódban: `evaluation_group`) kerül, és személyre szabott — de futásidőben teljesen szabályalapú, előre megírt — visszajelzést, valamint a szegmenséhez tartozó videót kapja. A cél egy 4–5 alkalmas 1:1 kurzuscsomag megvásárlása, erőszakos sürgetés nélkül. A praxist vezető szakember nem technikai felhasználó.

Ez a dokumentum a **ténylegesen megépített** állapotot írja le. Amit még nem építettünk meg, azt a „Tervezett, még nincs kódban” szakasz sorolja fel: jó ötletek a korábbi tervezésből, amiket egyeztetés után érdemes megcsinálni — de ne kezeld meglévő viselkedésként, és ne dönts helyettünk arról, hogy pontosan hogyan épüljenek meg.

## Tech stack

- Laravel 13, PHP 8.4, PostgreSQL
- Publikus felület: Blade + Tailwind CSS + Alpine.js, szerveroldali renderelés
- Admin felület: Filament 5, `/admin` alatt
- JSON séma validáció: `opis/json-schema` (a kvízkonfiguráció dokumentum-alakjának ellenőrzésére)
- Állapotgép: `spatie/laravel-model-states` — jelenleg a `ConfigVersion` állapotára használjuk (ld. lent); Lead-re még nincs, mert Lead entitás egyáltalán nem létezik még
- Webhookok: `spatie/laravel-webhook-client` — telepítve, `webhook_calls` tábla létezik, de még nincs hozzá kötve konkrét szolgáltató/kontraktus
- Queue: `database` driver (Postgres), nincs Redis
- Tesztek: Pest
- Fejlesztői környezet: Laravel Sail (Docker) WSL2 alatt, Mailpit a levelekhez
- Laravel Boost MCP: verzióspecifikus API (Laravel, Filament, Pest) használata előtt keress vele a dokumentációban

## Parancsok

Minden parancs a Sail konténerben fut. Mindig a teljes útvonalat használd (`./vendor/bin/sail`), mert a `sail` alias nem biztos, hogy létezik a shelledben.

```bash
./vendor/bin/sail up -d                                            # környezet indítása
./vendor/bin/sail artisan migrate:fresh --seed                     # adatbázis újraépítése (a seeder egyelőre csak admin usert hoz létre, ld. lent)
./vendor/bin/sail artisan test --parallel                          # teljes tesztcsomag
./vendor/bin/sail artisan test --filter=ConfigGenerationTest       # egy teszt vagy fájl
./vendor/bin/sail pint --dirty                                     # módosított fájlok formázása
./vendor/bin/sail npm run dev                                      # Vite dev szerver
./vendor/bin/sail artisan quiz-config:validate {path}              # kvízkonfiguráció JSON validálása (séma + referenciák + elérhetőség + kiegyensúlyozottság)
./vendor/bin/sail artisan quiz-config:evaluate {config} {answers}  # egy válaszvektor kiértékelése egy config-fájlon
./vendor/bin/sail artisan quiz-events:report {campaign-slug}       # funnel / lemorzsolódás / csoport-eloszlás lekérdezések egy kampányra
./vendor/bin/sail artisan quiz-events:export {campaign-slug}       # a kampány eseménynaplójának CSV-exportja
```

Címek: alkalmazás http://localhost, admin http://localhost/admin, Mailpit http://localhost:8025

## Architektúra

Monolit, klasszikus MVC.

- `routes/web.php`: publikus oldalak (kampányindítás slug alapján, kvíz, eredmény, admin-only preview)
- `/admin`: Filament panel
- `routes/api.php`: jelenleg üres — a külső szolgáltatók webhookjai ide jönnek majd (ld. Tervezett szakasz)

### Domain

- `Campaign`: központi entitás (`slug`, `name`); egy kampányhoz több `ConfigVersion` tartozik.
- `ConfigVersion`: a kampány kvízének **egy teljes, verziózott JSON dokumentuma** — nincs külön `Quiz`/`Question`/`AnswerOption`/`Segment` tábla, hanem egyetlen `content` mező (séma: `app/QuizConfig/Schema/quiz-config.schema.json`), benne:
  - `labels`: pontozási dimenziók
  - `questions`: kérdések, opciónként `label_weights`-szel
  - `evaluation_groups`: a „szegmensek” — szabályalapú találati feltétellel (`RuleExpressionInterpreter`: `label_sum`-összehasonlítás, `answer_selected`, `module_top_rank`, `or`-kombinátor), prioritás szerint sorba rendezve, pontosan egy `is_fallback` csoporttal; mindegyikhez `result_page` (cím, összefoglaló, szekciók, videó, CTA)
  - `modules`: relevancia-pontozott tartalmi blokkok a legjobb találatokból, amik az utánkövető emailekbe kerülnek
  - `emails`: `sequences` / `default_sequence` / `shared_blocks` / `module_content` — a teljes email-tartalom séma szinten már modellezve van, de a **tényleges kiküldés még nincs megírva** (ld. Tervezett szakasz)
  - `generation_trace`: ha a dokumentum LLM-mel készült, ennek forrása/utasítása/modellje
  - `content_hash`, `version_id`, `created_at`, `created_by`, `status`: mentéskor felülírt metaadatok
  - Két szintű validáció: `ConfigSchemaValidator` (séma-alak, `opis/json-schema`-val) minden mentéskor fut; a teljesebb `ConfigValidator` (referenciák, elérhetőség, kiegyensúlyozottság) csak az aktiválás átmenetén.
- `QuizSession`: egy látogató egy kitöltése — válaszok (+ `answers_hash` integritásellenőrzéshez), kiértékelt eredmény (`label_sums`, `matched_group_ids`, `ranked_modules`), `is_preview` (admin-előnézet nem számít valódi forgalomnak), `is_bot_suspected`.
- `QuizEvent`: csak hozzáfűzhető eseménynapló egy `QuizSession`-höz és `ConfigVersion`-höz. Ténylegesen diszpécselt típusok: `SessionStarted`, `QuestionShown`, `QuestionAnswered`, `EvaluationCompleted`, `ResultPageViewed`. Az enum tartalmaz még `EmailProvided` / `CtaClicked` / `EmailSent` / `EmailOpened` értékeket is, de ezeket **sehol nem diszpécseljük még** — a Lead/email-küldés bekötésére vannak fenntartva.
- `VisitorToken`: session-alapú (nem tartós cookie) látogatóazonosító; ugyanaz a token mindig ugyanazt az aktív variánst kapja.
- `VariantSelector`: a kampány `Active` állapotú `ConfigVersion`-jei közül `traffic_weight` szerint súlyozva, determinisztikusan választ a `VisitorToken` alapján — ez az A/B-teszt mechanizmus.
- `EvaluationEngine` / `RuleExpressionInterpreter`: a kiértékelés teljesen determinisztikus és szabályalapú, nincs benne futásidejű AI-hívás.
- `FeedbackGenerator` kontraktus (`app/Contracts`) + `FakeFeedbackGenerator` / `AnthropicFeedbackGenerator` implementáció (`app/Services/Generation`): **szerkesztési időben** segít az adminnak `ConfigVersion`-dokumentumot generálni forrásanyagból és utasításból (Filament „Generálás forrásból” akció, mindig admin saját szakmai anyaga, soha nem látogatói adat) — a human utána még átnézi, szerkeszti, csak azután menti. Driver configból jön (`FEEDBACK_GENERATOR_DRIVER`, alapértelmezett `fake`). Az Anthropic implementáció streamelt kérést küld (`stream: true`) — nem-streamelt kéréssel a nagy `max_tokens` és az alapértelmezetten bekapcsolt adaptív gondolkodás miatt rendszeresen kliensoldali időtúllépést kaptunk. **Ez nem ugyanaz, mint a lenti „Nyitott döntések” AI-alapú, futásidejű, látogatónkénti személyre szabott visszajelzés kérdése — az továbbra is teljesen nyitott és megépítetlen.**

### `ConfigVersion` állapotgép

| Állapot | Jelentés | Megengedett átmenetek |
|---|---|---|
| `Draft` (alapértelmezett) | szerkeszthető, `content` bármikor felülírható | `Active` |
| `Active` | publikált, forgalmat kaphat (a `VariantSelector` választhatja) | `Paused`, `Archived` |
| `Paused` | átmenetileg nem kap forgalmat | `Active`, `Archived` |
| `Archived` | végállapot | — |

Szabályok:

- Csak `Draft` tartalma szerkeszthető helyben (`updateDraftDocument()`); publikált verzió tartalma soha nem módosul. Ha egy publikált verziót módosítani kell, `cloneAsNewDraft()`-tal kell belőle új draftot csinálni.
- Az aktiválás (`ActivateConfigVersion` átmenet) lefuttatja a teljes `ConfigValidator`-t, nem csak a séma-alakot; érvénytelen dokumentum esetén `ConfigVersionNotValidException`-t dob, és az átmenet nem történik meg.
- Egy kampánynak egyszerre több `Active` verziója is lehet (`traffic_weight` szerinti A/B-teszt); az `experiment_compatible_with_previous` mező jelzi, hogy az eredmények összevethetők-e egy korábbi verzióval.
- A mérvadó leírás a `tests/Feature/Scenarios/AdminConfigManagementTest.php`-ban lévő eseteké (nincs külön dataset-alapú állapotgép-teszt fájl); ha ez a táblázat és a teszt eltér, a teszt a hiteles, és jelezd az eltérést.

## Admin (Filament)

Jelenleg: `CampaignResource` és `ConfigVersionResource` (utóbbi a kampány alá ágyazott `ConfigVersionsRelationManager`-en keresztül is elérhető), egy „Emailek előnézete” oldal, és a fenti LLM-alapú generálás akció. A kezdőoldal egyelőre a Filament alap `Dashboard` — **a napi teendőlista még nincs megépítve** (ld. Tervezett szakasz).

Csapda, amibe ebben a projektben már belefutottunk: egy resource formját ha relation manageren keresztül is szerkeszthetővé teszed, a Filament alapértelmezett `EditAction` **nem** ugyanazt a kitöltés/mentés logikát futtatja, mint az oldal saját `EditRecord`-ja (`mutateFormDataBeforeFill` / `handleRecordUpdate`) — alapból nyers `attributesToArray()`-t tölt be és `$record->update()`-tel ment. Ha egy mezőnek egyedi kitöltési/mentési logikája van (pl. `ConfigVersion::content` JSON-string ↔ array cast), azt mindkét helyen ugyanúgy kell futtatni (ld. `ConfigVersionResource::mutateFormDataBeforeFill()` / `saveFormData()`), különben a mező nyers PHP-tömbként — a böngészőben `[object Object]`-ként — jelenik meg, mentéskor pedig megkerüli a domain-logikát.

## Frontend

- Publikus felület: egyedi design Tailwinddel, mobile-first. JavaScript csak ahol kell (a kvíz léptetése Alpine-nal, `x-data`-val). Dinamikus értéket `x-data`-ba mindig `Illuminate\Support\Js::from()`-mal ágyazz be, soha `@json()`-nal egy dupla idézőjeles attribútumba — a `@json()` saját idézőjelei idő előtt lezárják az attribútumot, és csendben, futásidőben derül csak ki (Alpine JS-hiba a böngésző konzolján, pl. „step is not defined”).
- Hirdetési és analitikai script csak süti-hozzájárulás után töltődhetne be — ez még nincs megépítve (nincs hirdetési integráció, ld. Tervezett szakasz), de ha épül, ez a szabály rá is vonatkozik.
- Admin (Filament): egyszerű, magyar nyelvű felület, kevés mező, érthető címkék.

## Konvenciók

- A felület nyelve magyar (fordítások a `lang/hu` alatt); kód, azonosítók, kommentek és commit üzenetek angolul.
- Pénzösszeg egész számként, forintban, soha nem float. (Egyelőre nincs a sémában ár/pénzösszeg mező — ez a szabály a jövőbeli `Package`/fizetés munkára vonatkozik.)
- Adatbázisban UTC; megjelenítés és határidő-számítás `Europe/Budapest` szerint.
- `env()` csak a `config/` fájlokban.
- Új composer vagy npm csomagot csak egyeztetés után vegyél fel.
- Kis, egy témájú változtatások; commit csak zöld tesztekkel.

## Adatvédelem

Ezek standing szabályok — a mögöttük lévő funkciók egy része (hirdetési integráció, email-küldés, Lead) még nincs megépítve, de tervezéskor és építéskor is tartsd be őket.

- A kvízválaszok különleges kategóriájú (pl. egészségügyi) adatnak minősülhetnek. Kvízválasz és szegmens soha nem mehet hirdetési platformra (Meta Pixel, Conversions API); oda csak semleges konverziós esemény mehet (pl. „kvíz kitöltve”, „email megadva”).
- Marketing levél csak double opt-in hozzájárulás után, minden marketing levélben leiratkozó linkkel.
- Személyes adat nem kerülhet logba.
- Törlési kérelemnél a Lead (ha megépül) anonimizálódik; az események személyes adat nélkül megmaradnak a statisztikához.
- AI szolgáltatónak személyes adat vagy kvízválasz csak kifejezett döntés után mehet (lásd: Nyitott döntések). A meglévő `FeedbackGenerator`/Anthropic-integráció ezt nem sérti: csak az admin által megadott szerkesztési célú forrásanyagot és utasítást küldi, látogatói adatot vagy kvízválaszt soha.

## Tesztelés

A rendszert elsősorban két teszttípus védi:

1. **Forgatókönyv-tesztek** (`tests/Feature/Scenarios/`): a funnel egy-egy útvonala HTTP-n/Livewire-n keresztül, valódi Postgresen, fake külső szolgáltatásokkal és időutazással. Kívülről írják le a viselkedést, a belső felépítést nem ismerik.
2. **Unit- és motor-tesztek** (`tests/Unit/`): tiszta, determinisztikus logikára (séma-/config-validátorok, `EvaluationEngine`/`RuleExpressionInterpreter`, `FeedbackGenerator`-implementációk) proaktívan íródnak, nem csak kérésre — ez már a tényleges gyakorlat, nem csak engedmény.

Emellett van egy harmadik, kevésbé szigorú kategória: `tests/Feature/QuizConfig/` — modell-/motorközeli feature tesztek (pl. `ConfigVersionTest`, `QuizSessionTest`, `FunnelQueriesTest`), amik nem admin/publikus flow-t írnak le végponttól végpontig, de DB-t használnak. Ezekre a fenti védelmi szabály (ld. lent) nem vonatkozik olyan szigorúan, mint a Scenarios/ könyvtárra.

### Szabályok

- Meglévő tesztet a `tests/Feature/Scenarios/` könyvtárban NEM módosíthatsz és nem törölhetsz azért, hogy átmenjen. Ha egy változtatás miatt szükséges, állj meg, és magyarázd el, mi és miért változna. Ez shellen keresztül is érvényes: ne kerüld meg `sed`-del vagy fájlírással. (Ha a jövőben lesz dedikált dataset-alapú állapotgép-teszt fájl — pl. Lead-átmenetekre —, ugyanez a védelem vonatkozzon rá is.)
- Új funkciónál: először a forgatókönyv-tesztet írd meg és futtasd (el kell buknia), mutasd meg, és csak jóváhagyás után implementálj. Tiszta hibajavításnál ez nem kötelező, de a hibát reprodukáló regressziós teszt így is elvárt.
- Egy feladat akkor kész, ha a `./vendor/bin/sail pint --dirty` lefutott, és a `./vendor/bin/sail artisan test --parallel` zöld.
- A CI (`.github/workflows/ci.yml`) minden push-nál és pull requestnél lefuttatja a teljes csomagot Postgres service-szel.

### Gyorsaság

- Iteráció közben csak az érintett tesztet futtasd (`--filter`), a teljes csomagot a végén, párhuzamosan.
- Hálózat tilos: a `tests/Pest.php` globálisan hívja a `Http::preventStrayRequests()`-et (Feature és Unit csoportra is). A külső válaszok a `tests/Fixtures/{provider}/*` fájlokból jönnek (pl. `tests/Fixtures/anthropic/messages-stream-response.txt`).
- Idő: `$this->travel()` és `$this->freezeTime()`, soha `sleep`.
- Tesztben nincs frontend build: a base `TestCase` `withoutVite()`-ot hív.
- A `phpunit.xml`-ben: `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, cache és session `array`.
- `RefreshDatabase` (csak a Feature csoportra); ha a migrációk száma megnő, `schema:dump`.
- Tesztadat factory-kkal; közös tesztsegédek a `tests/Support/` alatt (pl. `QuizConfigFixture::example()` egy érvényes teljes dokumentumért).

## Lokális fejlesztés

- WSL2 (Ubuntu 24.04) + Docker Desktop. A repó a Linux fájlrendszerben van (`~/code/marketing-tool`, disztribúció: `Ubuntu-24.04`, user: `dev`), soha nem `/mnt/c` vagy `/mnt/d` alatt — a Windows-oldali bind mount 10-20x lassabb (mért érték: a teljes tesztcsomag 11-20s helyett 0.66s, a főoldal ~330ms helyett ~35ms). Parancsok: `wsl.exe -d Ubuntu-24.04 -- bash -c "cd ~/code/marketing-tool && ./vendor/bin/sail ..."`. Fájlszerkesztéshez a `\\wsl.localhost\Ubuntu-24.04\home\dev\code\marketing-tool\...` UNC-útvonal használható Windows-os eszközökből (pl. Claude Code, VS Code Remote-WSL).
- A `D:\Projects\marketing-tool` egy 2026-09-11 előtti, elavult másolat (natív Windows + Docker Desktop bind mount alatt jött létre, mielőtt a projekt átköltözött WSL-re) — ne onnan dolgozz tovább, biztonságosan törölhető.
- A `vendor/laravel/sail/bin/sail` szkriptet egy `composer.json` `post-autoload-dump` hook (`scripts/patch-sail-for-windows.php`) foltozza, hogy Git Bash/MSYS alól (natív Windows) is fusson — ez a WSL2 alatti natív Linuxon ártalmatlan no-op, csak akkor kell, ha valaki mégis natív Windows alól futtatná.
- A lokális Postgres a `compose.yaml`-ban `fsync=off`, `synchronous_commit=off`, `full_page_writes=off` kapcsolókkal fut (csak fejlesztéshez).
- `migrate:fresh --seed`: jelenleg csak egy admin felhasználót hoz létre (`admin@example.com` / `password`). Demó kampány/kvíz/leadek seedelése még nincs megírva — jó ötlet lenne (ld. Tervezett szakasz).
- A `.env.example`-ben minden külső szolgáltatás `fake`/üres driverrel szerepel (pl. `FEEDBACK_GENERATOR_DRIVER=fake`). Valódi sandbox kulcsok csak a `.env`-ben vannak; azt ne olvasd, új kulcsot a `.env.example`-be vegyél fel.

## Nyitott döntések – ezekben ne dönts helyettünk, kérdezz

- A praxis szakterülete (befolyásolja a GDPR-kategóriát és a hirdetési szabályokat)
- Fizetés: Barion, SimplePay vagy Stripe
- Számlázás: Számlázz.hu vagy Billingo
- Emailküldő (Postmark, Resend, Brevo), és hogy a sorozatokat mi építjük, vagy Brevo/MailerLite kezeli
- Személyre szabott, **futásidejű, látogatónkénti** visszajelzés: kezdetben szabályalapú (ez van most); AI csak később, ellenőrzött kimenettel. Fontos: ez különbözik a már megépített, szerkesztési idejű `FeedbackGenerator`-tól (ld. Architektúra) — az admin oldali config-generálás AI-val nem dönti el ezt a kérdést.
- Hosting

## Tervezett, még nincs kódban — jó ötletek a korábbi tervezésből, egyeztetés után érdemes megépíteni

- **`Lead` entitás + állapotgép.** A kitöltő; email nélkül is létrejön. Korábbi tervezet:

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

  Tervezett szabályok: állapotot csak átmeneten keresztül váltani (`$lead->state->transitionTo(...)`); minden átmenet ugyanabban a DB-tranzakcióban `LeadEvent`-et ír (a `QuizEvent` mintájára, vagy azzal összevonva); az állapot azt mondja meg, hol tart a lead, a teendőket a `FollowUpRule`-ok hozzák létre időalapon; vásárlás utáni marketing-hozzájárulás visszavonása nem állapotváltás, hanem `marketing_consent_withdrawn_at` mező (a kurzushoz tartozó tranzakciós levelek továbbra is mennek).
- **`FollowUpRule`** (kampányonkénti időszabály, pl. „`OfferSent` után 3 nap → email teendő, 7 nap → hívás teendő”) + **`Task`** (a szakember teendője: típus, esedékesség, lead, lezárás) + **Filament napi teendőlista** admin kezdőlapként (lejárt és mai teendők, egy kattintással lezárhatók).
- **`funnel:process-deadlines`** ütemezett (5 percenként), idempotens parancs, ami a lejárt `FollowUpRule`-ok alapján teendőt hoz létre (ugyanarra a szabályra/leadre kétszer nem). Teendő lezárása esemény, ami átmenetet is kiválthat (pl. hívás teendő lezárása → `CallCompleted`). Hosszú várakozásra ne késleltetett job kelljen — az időalapú logika mindig az adatbázisban tárolt időpontokból számoljon, hogy tesztben időutazással ellenőrizhető legyen.
- Csak lokális környezetben elérhető **`funnel:shift-time {lead} {--days=}`** dev segédparancs, ami a lead időpontjait eltolja, hogy a határidős folyamatok kézzel is kipróbálhatók legyenek.
- **Email-megadás** double opt-in-nel + **leiratkozás** route a publikus felületen (`routes/web.php`).
- **`PaymentGateway` / `InvoiceProvider` / `AdsPlatform`** kontraktusok `app/Contracts` alatt, `Fake…` implementációval és config-driven driverrel (a `FeedbackGenerator` már megépített mintája szerint) — Controller/Filament resource/job csak az interfészt kapja (DI), SDK-t sosem hív közvetlenül. A webhook-infrastruktúra (`spatie/laravel-webhook-client`) már telepítve van, csak szolgáltató nincs rákötve; feldolgozás queue jobban, idempotensen (a szolgáltató eseményazonosítója egyedi).
- **Cal.com időpontfoglalás**: a foglalás webhookja váltja a Lead állapotát `CallBooked`-ra.
- **`Package`** modell (ár, alkalmak száma és hossza, garancia; több kampány is használhatja) — jelenleg a séma nem tartalmaz ár-/csomagadatot.
- Gazdagabb `migrate:fresh --seed`: demó kampány kvízzel, minden `ConfigVersion`-állapotban legalább egy példány, és (miután a `Lead` megépült) Lead minden állapotban.

## Első mérföldkő — állapot

**Kész:** kampány + kvíz + eredményoldal (szegmens-visszajelzés, videó); admin kampány- és configverzió-kezelés Filamenttel, LLM-segített config-generálással; eseménynapló és alap funnel-lekérdezések.

**Hiányzik:** email-megadás double opt-innel, Lead állapotgép és eseménynapló, Filament lead-lista és napi teendőlista, Cal.com foglalási link. Fizetés, számlázás és hirdetés-API továbbra is később jön.

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
