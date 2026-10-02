# Verfahrensdokumentation: Unveränderbarkeit und Nachvollziehbarkeit der Datensätze

> Vorlage aus `mueller-schmitz/laravel-model-integrity`. Sie beschreibt nur den Teil des Verfahrens, den das Package abdeckt, und muss an Ihre Anwendung und Organisation angepasst werden. Platzhalter stehen in `[[ … ]]`. Die Vorlage ist keine Rechts- oder Steuerberatung.

| | |
|---|---|
| Unternehmen | [[Firma, Anschrift]] |
| Anwendung | [[Name, Zweck, Version]] |
| Verantwortlich | [[Name, Funktion]] |
| Stand | [[Datum]] |
| Package-Version | [[z. B. mueller-schmitz/laravel-model-integrity 0.4.x]] |

## 1. Allgemeine Beschreibung

### 1.1 Zweck

Die Anwendung [[Name]] verarbeitet die folgenden steuerlich relevanten Daten: [[z. B. Rechnungen, Belege, Stammdaten]]. Jede Erfassung, Änderung und Löschung dieser Datensätze wird unveränderbar protokolliert (Unveränderbarkeit nach § 146 Abs. 4 AO, GoBD Rz. 58 ff.). Manipulationen außerhalb der Anwendung werden nicht verhindert, aber erkannt.

### 1.2 Erfasste Datensätze

| Model / Tabelle | Inhalt | Modus | Löschungen | Personenbezogene Attribute |
|---|---|---|---|---|
| [[App\Models\Invoice]] | [[Ausgangsrechnungen]] | [[immutable / versioned]] | [[forbid / record]] | [[keine]] |

- `immutable`: Nach der Erfassung keine Änderung möglich; Korrekturen erfolgen durch [[Storno-/Korrekturbuchung]].
- `versioned`: Jede Änderung wird als neue Version gespeichert; frühere Stände bleiben erhalten.

## 2. Anwenderdokumentation

- Wer erfasst und ändert welche Datensätze: [[Rollen, Berechtigungen]].
- Bei Korrekturen wird ein Grund angegeben: [[Verfahren, z. B. Pflichtfeld im Formular]].
- Gründe und Kontextangaben enthalten keine personenbezogenen Daten.

## 3. Technische Systemdokumentation

### 3.1 Aufzeichnung

- Für jeden Vorgang speichert das Package eine Version mit dem vollständigen Stand des Datensatzes (Snapshot), dem handelnden Benutzer, dem Zeitpunkt (UTC) und einem Hash. Model-Änderung und Version werden in derselben Datenbanktransaktion geschrieben.
- Die Versionen bilden zwei Hash-Ketten: je Datensatz und global über alle Datensätze mit lückenloser Sequenz. Das Hash-Format ist versioniert und in der Datei `SPEC.md` jedes Prüfer-Exports beschrieben.
- Dateien (z. B. Belege) werden unter ihrem SHA-256-Hash gespeichert und sind Teil der globalen Kette. Speicherort: [[Disk, System]].

### 3.2 Schutz auf Datenbankebene

- Trigger lehnen Änderungen und Löschungen der Versionen, Dateien und Anker ab.
- Der Datenbank-Benutzer der Anwendung hat auf diesen Tabellen nur Lese- und Einfügerechte (`php artisan model-integrity:grants`). Migrationen laufen mit einem getrennten Benutzer: [[Name des Benutzers]].

### 3.3 Externe Verankerung

- Neue Versionen werden [[stündlich]] außerhalb der Datenbank verankert (`model-integrity:anchor`), mit: [[Disk-Treiber auf …, OpenTimestamps, RFC-3161-Zeitstempeldienst …]].
- Ein Anker enthält die Merkle-Wurzel der Versionen seines Bereichs und den Digest des vorherigen Ankers. Ausstehende OpenTimestamps-Beweise werden [[stündlich]] vervollständigt (`model-integrity:anchor-upgrade`).

### 3.4 Personenbezogene Daten

- Personenbezogene Attribute werden je betroffener Person verschlüsselt gespeichert. Auf Löschverlangen (Art. 17 DSGVO) wird nach Ablauf der Aufbewahrungsfristen der Schlüssel vernichtet (`model-integrity:shred`); die Kette bleibt prüfbar.
- Vor Ablauf der Aufbewahrungsfrist (§ 147 AO) werden steuerlich relevante Daten nicht gelöscht: [[Regelung, Zuständigkeit]].
- Die Anonymisierung der Anwendungstabellen erfolgt durch: [[Verfahren]].

## 4. Betriebsdokumentation

| Vorgang | Befehl | Turnus | Verantwortlich |
|---|---|---|---|
| Prüfung der Ketten, Anker und Datensätze | `php artisan model-integrity:verify` | [[täglich]] | [[…]] |
| Prüfung der Dateiinhalte | `php artisan model-integrity:verify --files` | [[wöchentlich]] | [[…]] |
| Verankerung | `php artisan model-integrity:anchor` | [[stündlich]] | [[…]] |
| Vervollständigung der OpenTimestamps-Beweise | `php artisan model-integrity:anchor-upgrade` | [[stündlich]] | [[…]] |
| Datensicherung | [[Verfahren]] | [[…]] | [[…]] |

- Befunde der Prüfung werden gemeldet an: [[Empfänger, Kanal]] und behandelt nach: [[Verfahren]].
- Wiederherstellung einer Datensicherung: Vorgehen und Dokumentation nach README, Abschnitt „Restoring a backup“: [[…]].
- Änderungen an Konfiguration, Treibern oder Package-Version werden dokumentiert in: [[…]].

## 5. Internes Kontrollsystem

- Trennung der Berechtigungen: Anwendung (nur Lesen und Einfügen der Protokolltabellen), Migration, Datenbankadministration, Zugriff auf den Anker-Speicher: [[Personen/Rollen]].
- Der Anker-Speicher ist für die Anwendung und die Datenbankadministration nicht schreibbar: [[Umsetzung, z. B. Object Lock]].
- Regelmäßige Kontrolle der Prüfberichte durch: [[…]].

## 6. Datenzugriff (Z1–Z3)

- Z3 (Datenträgerüberlassung): `php artisan model-integrity:export <Verzeichnis> [--from=…] [--to=…]` erzeugt `index.xml` und CSV-Dateien nach dem Beschreibungsstandard (GDPdU), einen Prüfbericht, die Spezifikation (`SPEC.md`), Beweisdateien und Prüfsummen.
- Z1/Z2: [[Verfahren für den Lesezugriff im System]].
