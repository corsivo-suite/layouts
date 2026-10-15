# Corsivo Layouts

Layout di partenza per l'editor a blocchi: strutture canoniche di blocchi dichiarate in PHP e applicate al contenuto dal pannello Riepilogo, per post type. Namespace `Corsivo\Layouts`, parte della suite Corsivo.

## Scopo

I contenuti dei siti cliente nascono quasi sempre dalla stessa struttura: una landing, una scheda, un articolo con hero. Invece di partire da un editor vuoto o duplicare contenuti, i provider (tema o plugin) dichiarano content template — layout canonici di blocchi per contesto — e l'editor li propone in una modale, con applicazione confermata e annullabile. Per sviluppatori che definiscono le strutture editoriali; l'editor finale sceglie una card e scrive.

## Come funziona

**Provider** — tutto passa dal filtro `corsivo_layouts_templates( $collections, $context )`: collezioni indicizzate per post type, chiave speciale `all` valida per tutti; il context porta `post_type` e `post_id`. Chiavi della collezione: `templates`, `open_on_new_post` (apre la modale sul nuovo contenuto vuoto), `inherit_all` (default `true`; a `false` esclude sia i layout sia il fallback `open_on_new_post` di `all`). Chiavi del template: `id` (se assente, derivato con hash da nome, blocchi e page template — `description` e `image` non cambiano l'identità), `name`, `description`, `blocks` nel formato template di core (`[ $block_name, $attributes, $inner_blocks ]`), `image` (screenshot al posto dell'anteprima generata), `page_template` (file registrato per quel post type; stringa vuota ripristina il default, chiave assente non tocca quello corrente).

**Normalizzazione fail-closed** — limiti espliciti: 100 template per schermata (500 candidati esaminati), 250 blocchi per template, profondità 20, 256 KB per template, 2 MB di configurazione totale. Nomi blocco validati, attributi serializzabili, blocchi fuori dalla allowlist dell'editor (`get_allowed_block_types`) esclusi, id duplicati e page template inesistenti rifiutati. Ogni scarto emette l'action `corsivo_layouts_template_rejected` con `reason` (e `detail` per `invalid_blocks`: struttura, profondità, quantità, attributi) e `_doing_it_wrong()` in debug. A parità di id la collezione del post type prevale su `all`.

**Consegna all'editor** — enqueue solo nelle schermate post con utente autorizzato (`edit_post` sul post o `create_posts` sul post type); la configurazione arriva come JSON congelato in `window.corsivoLayoutsConfig`. Bundle mancante o metadata asset invalidi emettono l'action `corsivo_layouts_editor_asset_issue` con la `reason`.

**UI** — bottone "Scegli un layout" nel pannello Riepilogo (`PluginPostStatusInfo`) e modale a card; auto-apertura sul nuovo contenuto vuoto quando `open_on_new_post` è attivo. La UI non compare se la struttura è bloccata (`templateLock` o blocchi non rimovibili).

**Applicazione** — sostituisce tutti i blocchi correnti (con conferma esplicita se il contenuto non è vuoto) ed eventualmente imposta il page template, in un'unica `editPost`. Lo snackbar "Layout applicato" offre Annulla, che ripristina blocchi e page template solo se il contenuto non è cambiato nel frattempo; blocchi non registrati nel client bloccano l'applicazione per intero, mai parziale.

**Anteprime** — `BlockPreview` live, lazy via IntersectionObserver, o screenshot `image` se dichiarato. Le immagini remote e i blocchi che referenziano risorse remote non consentite vengono neutralizzati: screenshot solo same-origin e anteprima live sostituita da un placeholder ("Anteprima protetta"), salvo opt-in esplicito via `corsivo_layouts_allow_remote_preview`. Il controllo include gli sfondi `style.background.backgroundImage` condivisi dai blocchi, anche annidati, sia come oggetto con `url` sia come stringa CSS. La mappa degli attributi che caricano risorse copre i blocchi core ed è estendibile per i blocchi custom via `corsivo_layouts_preview_resource_attributes`; i default core non sono rimovibili, solo estendibili.

## Punti rilevanti

- Nessuna persistenza propria: niente opzioni, niente tabelle, niente meta. I layout vivono nel codice dei provider e il risultato è normale contenuto a blocchi core — disattivando il plugin i contenuti restano intatti.
- Privacy delle anteprime: nessuna risorsa remota viene caricata nell'editor senza opt-in, incluse le varianti malformate degli URL (protocol-relative, slash anomali).
- Fail-closed con diagnostica: i layout invalidi spariscono in produzione e fanno rumore in debug, sempre con l'action osservabile.
- Un solo file PHP più il bundle editor: superficie minima, nessuna classe, tutto in namespace.
- Requisiti dichiarati: WordPress 6.6+, PHP 8.1+. Versione 1.2.1.

## Integrazioni con la suite

- **Corsivo Mail** — `includes/integrations/layout-selector.php` registra gli starter delle email sul CPT `corsivo_mail_layout` via `corsivo_layouts_templates` (con `open_on_new_post` e `inherit_all => false`), esponendoli a sua volta al filtro `corsivo_mail_starter_templates`.
- **Plugin cliente demo** — dieci site plugin (cinema, rifugio-multilingua, casa-vacanze, coworking, studio-medico, donazioni, fondazione, bando, calcetto, archivio-media) dichiarano i propri layout in `includes/layouts.php` e li esercitano nei rispettivi `tests/smoke.php`.
- **Hook esposti** (nomi esatti): filtri `corsivo_layouts_templates`, `corsivo_layouts_allow_remote_preview`, `corsivo_layouts_preview_resource_attributes`; action `corsivo_layouts_template_rejected`, `corsivo_layouts_editor_asset_issue`.
- **Corsivo Updater** — canale privato dichiarato nell'header `Update URI: https://www.corsivo.dev/plugins/corsivo-layouts`.

## Test

Regressioni isolate sugli sfondi, senza database (dalla root del plugin):

```bash
php tests/background-preview-regressions.php
```

Il runner usa le funzioni reali del core WordPress; `WP_CORE_DIR` permette di indicare una installazione esterna. Verifica sfondi remoti, locali, annidati, URL malformati, opt-in e assenza di falsi positivi sui link testuali.

Verifiche complete dell’editor e smoke con WordPress:

```bash
cd editor
npm run lint:js
npm run lint:style
npm run build
cd ../../../..
wp eval-file wp-content/plugins/corsivo-layouts/tests/runtime-smoke.php --skip-plugins --skip-themes
```

Il runner carica da sé il plugin, crea un post fixture e verifica: normalizzazione e precedenze tra collezione specifica e `all`, `inherit_all`, policy delle anteprime remote (inclusi URL malformati e mappe risorse corrotte), allowlist dei blocchi, limiti di profondità e quantità con i relativi `detail`, diagnostica degli asset editor.

## Configurazione

Nessuna pagina di impostazioni e nessuna opzione: tutto si dichiara via filtri dal tema o dai plugin provider.

## Bootstrap, lifecycle, sicurezza e build

corsivo-layouts.php è bootstrap e intera implementazione PHP namespaced: normalizza i provider e aggancia enqueue_block_editor_assets. Non registra activation, deactivation, uninstall, cron, REST, AJAX o CLI perché non possiede storage. L’unico ingresso runtime è l’editor Gutenberg; i provider entrano dal filtro corsivo_layouts_templates. L’enqueue richiede edit_post o create_posts e il client applica solo template già normalizzati; non ci sono nonce perché non esiste endpoint proprio e il salvataggio passa dal data store core. Build reale: cd editor; npm ci; npm run lint:js; npm run lint:style; npm run build. La dipendenza privata `@corsivo-suite/wp-admin-ui` è bloccata alla release 0.1.0 e viene scaricata da GitHub Packages durante installazione e build; il plugin distribuito usa esclusivamente gli asset già compilati in `editor/build` e non richiede il package a runtime. Limiti: solo editor a blocchi, nessuna persistenza, massimo 100 template esposti e policy fail-closed già descritta.

## Inventario completo dei file

Ogni percorso è relativo alla root del plugin. `editor/node_modules/` è raggruppata come dipendenza npm; non sono presenti directory `vendor/` o `libs/tcpdf/`.

- `README.md` — contratto operativo corrente di provider, normalizzazione, preview, applicazione, build, test e inventario.
- `.gitignore` — esclude `editor/node_modules` e gli archivi ZIP; la build resta tracciata.
- `editor/.gitignore` — esclude `node_modules` dell'editor.
- `changelog.txt` — storia delle release e delle policy di normalizzazione/preview.
- `corsivo-layouts.php` — intera implementazione PHP: registry provider, validazione fail-closed, policy remote e enqueue editor.
- `editor/build/index-rtl.css` — CSS RTL compilato della modale Layouts.
- `editor/build/index.asset.php` — dipendenze WordPress e hash del runtime editor generati dalla build.
- `editor/build/index.css` — CSS LTR compilato di bottone, modale, card e preview.
- `editor/build/index.js` — runtime compilato che registra il plugin Gutenberg e applica/annulla i layout.
- `editor/package-lock.json` — lock npm riproducibile delle dipendenze dell'editor.
- `editor/package.json` — dipendenze WordPress e script `build`, `start`, `lint:js`, `lint:style` dell'editor React.
- `editor/src/editor.scss` — stile sorgente di status info, modale, griglia card e preview protetta.
- `editor/src/index.js` — registra `corsivo-layouts`, bottone Riepilogo e auto-apertura sui nuovi contenuti vuoti.
- `editor/src/templateBlocks.js` — attraversa i template e restituisce i block type non registrati nel client.
- `editor/src/TemplateCard.js` — card con screenshot same-origin o `BlockPreview` lazy e placeholder per risorse remote.
- `editor/src/TemplatesModal.js` — modale di scelta, stato applicazione, error notice e griglia dei template normalizzati.
- `editor/src/useApplyTemplate.js` — parse blocchi, conferma sostituzione, `editPost` atomico e undo protetto da stato/post.
- `LICENSE` — testo della licenza GPL-2.0-or-later.
- `tests/background-preview-regressions.php` — regressioni CLI isolate della policy sugli sfondi, senza database.
- `tests/runtime-smoke.php` — smoke WordPress su merge provider, limiti, allowlist, diagnostica e privacy preview.
