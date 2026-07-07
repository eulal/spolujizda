# 🚗 Spolujízda

Webová aplikace pro organizaci sdílených jízd na semináře a akce.
Řidiči nabízí volná místa ve svém autě, spolucestující se přihlašují
online – vše bez nutnosti registrace. Aplikace automaticky rozesílá
e-mailová upozornění a počítá úsporu CO₂.

Postaveno na [Nette](https://nette.org) frameworku (PHP 8.2+).


## ✨ Hlavní funkce

- **Správa akcí** – vytváření akcí/seminářů s datem, místem a popisem
- **Nabídky jízd** – řidiči zadávají odkud jedou, počet volných míst, čas odjezdu
- **Přihlašování spolucestujících** – jednoduché přihlášení bez registrace
- **Poptávky jízd** – kdo nemá svoz, může zadat poptávku a být upozorněn
- **E-mailové notifikace** – automatické potvrzení, upozornění řidiče i spolucestujících
- **Editace přes token** – každý řidič/spolucestující obdrží unikátní odkaz pro úpravu
- **Směr jízdy** – rozlišení jízd „tam" a „zpět"
- **CO₂ kalkulačka** – výpočet úspory emisí díky sdílení jízd
- **Administrace** – chráněný admin panel pro správu akcí, jízd a nastavení
- **Automatické aktualizace** – kontrola nových verzí z GitHubu, migrace databáze
- **Responzivní design** – funguje na mobilu i desktopu


## 📋 Požadavky

- PHP ≥ 8.2
- MySQL / MariaDB
- Composer
- Git (volitelné – pro automatické aktualizace a detekci verze)


## 🚀 Instalace

### 1. Naklonování repozitáře

```bash
git clone https://github.com/OWNER/spolujizda.git
cd spolujizda
```

### 2. Instalace závislostí

```bash
composer install
```

### 3. Konfigurace

Zkopírujte vzorový konfigurační soubor a vyplňte skutečné hodnoty:

```bash
cp config/local.neon.example config/local.neon
```

Otevřete `config/local.neon` a nastavte:

| Parametr | Popis |
|---|---|
| `adminPasswordHash` | Bcrypt hash hesla pro admin panel |
| `baseUrl` | URL aplikace (pro odkazy v e-mailech) |
| `mailFrom` | Odchozí e-mailová adresa |
| `mailFromName` | Jméno odesílatele e-mailů |
| `co2EmissionFactor` | Faktor emisí CO₂ (kg CO₂/km), výchozí `0.15` |
| `githubRepo` | GitHub repozitář pro kontrolu aktualizací |
| `database.*` | Přístupové údaje k MySQL databázi |
| `mail.*` | Nastavení SMTP serveru |

**Vygenerování hesla pro admin:**

```bash
php -r "echo password_hash('vase_heslo', PASSWORD_BCRYPT);"
```

### 4. Vytvoření databáze

Importujte schéma do vaší MySQL databáze:

```bash
mysql -u spolujizda -p spolujizda < app/Model/db_schema.sql
```

Poté spusťte migrace (v admin panelu nebo ručně):

```bash
mysql -u spolujizda -p spolujizda < migrations/001_create_migrations_table.sql
mysql -u spolujizda -p spolujizda < migrations/002_baseline.sql
```

### 5. Oprávnění adresářů

Ujistěte se, že webový server může zapisovat do složek `temp/` a `log/`:

```bash
chmod -R 777 temp/ log/
```

### 6. Nastavení webového serveru

Nasměrujte document root na složku `www/`.

**Pro rychlé otestování** můžete použít vestavěný PHP server:

```bash
php -S localhost:8000 -t www
```

Poté otevřete `http://localhost:8000` v prohlížeči.

**Pro Apache** – `.htaccess` v `www/` je již součástí projektu.

**Pro Nginx** – příklad konfigurace:

```nginx
server {
    listen 80;
    server_name spolujizda.example.com;
    root /var/www/spolujizda/www;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

> ⚠️ **Důležité:** Adresáře `app/`, `config/`, `log/` a `temp/` nesmí být
> přístupné z webu. Viz [bezpečnostní varování Nette](https://nette.org/security-warning).


## 🏗️ Struktura projektu

```
spolujizda/
├── app/
│   ├── Core/                 # Služby jádra (routing, autentizace, migrace, aktualizace)
│   ├── Model/                # Repozitáře, služby (DB, e-mail, CO₂)
│   │   └── db_schema.sql     # Kompletní databázové schéma
│   ├── Presentation/         # Presentery a Latte šablony
│   │   ├── Admin/            # Administrace
│   │   ├── Event/            # Správa akcí
│   │   ├── Home/             # Úvodní stránka
│   │   ├── Ride/             # Nabídky a poptávky jízd
│   │   └── @layout.latte     # Hlavní layout
│   └── Bootstrap.php         # Bootstrap aplikace
├── config/                   # Konfigurační soubory (NEON)
├── migrations/               # SQL migrace databáze
├── www/                      # Document root (veřejné soubory)
├── temp/                     # Cache a dočasné soubory
├── log/                      # Logy aplikace
└── version.php               # Fallback definice verze
```


## 🔄 Aktualizace

Aplikace obsahuje vestavěný systém aktualizací dostupný v admin panelu.
Verze se automaticky čte z Git tagů.

**Ruční aktualizace:**

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
```

Migrace se spustí automaticky po přihlášení do administrace, nebo lze
provést ručně v sekci **Admin → Aktualizace**.


## 📝 Verzování

Verze se řídí formátem [Semantic Versioning](https://semver.org/).
Novou verzi vytvoříte Git tagem:

```bash
git tag v1.1.0
git push origin v1.1.0
```

Soubor `version.php` slouží jako fallback pro prostředí bez přístupu ke Gitu.


## 📄 Licence

Tento projekt je licencován pod [GNU GPL v3 licencí](LICENSE).
