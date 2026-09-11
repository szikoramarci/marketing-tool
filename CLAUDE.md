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

- WSL2 (Ubuntu) + Docker Desktop. A repó a Linux fájlrendszerben van (`~/code/...`), soha nem `/mnt/c` alatt.
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
