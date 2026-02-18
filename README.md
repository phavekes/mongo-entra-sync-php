# Mongo Entra Sync php

This is a Proof of concept for syncing between a mondo database of users and entra ID

## Setup

- Clone this repo
- Run `composer install`
- Copy `config.yaml.dist` to `config.yaml`, and edit to your mongoDB and Graph API credentials
- Run `sync.php`

## Access to Graph API

- Log in to the entra portal
- Open the "Microsoft entra ID page, and copy the tennant id to config.yml
- Maak bij app-registraties een nieuwe registratie aan.
- Kies een naam
- Kopieer de Toppassings-id als clientID in de config.yml
- Klik in het linkermenu van de app-pagina op Certificates & secrets.
- Ga naar het tabblad Client secrets en klik op + New client secret.
- Kies een naam en zet de verloopdatum zo ver mogelijk in de toekomst
- Maak een notitie in je agenda om deze tijdig te vervangen
- Kie "Toevoegen"
- Kopieer de "Waarde" als clientSecret in config.yml
- Klik in het linkermenu op API permissions.
- Klik op + Add a permission en kies Microsoft Graph.
- Selecteer Application permissions (NIET Delegated permissions).
- Zoek naar de volgende machtigingen en vink ze aan: User.ReadWrite.All (Om gebruikers aan te maken en te wijzigen). Directory.ReadWrite.All (Vaak nodig voor custom extension attributes).
- Klik op Add permissions.
- Admin Consent: Je ziet nu een waarschuwing dat er nog geen toestemming is. Klik op de knop Grant admin consent for [Jouw Organisatie] en bevestig dit.