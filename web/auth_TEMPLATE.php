<?php

/**
 * Kopieer naar web/auth.php op de server (en lokaal). NOOIT committen:
 * web/auth.php staat in .gitignore en de deploy slaat auth.php over.
 */

// Wie mag Thales bekijken. Lokaal/localhost (nightly) wordt de eerste gebruiker gebruikt.
$allowedUsers = [
    'tfalken@kvt.nl',
];

// Beheerders (zien foutdetails in de statusregel). Weglaten = iedereen in $allowedUsers.
$thalesAdmins = [
    'tfalken@kvt.nl',
];

// App-registratie in Entra (confidential client, alleen app-only / client credentials).
// 1. Entra admin center > Identiteit > Toepassingen > App-registraties > Nieuwe registratie, naam "Thales".
//    Overzicht: "Map-id (tenant-id)" en "Toepassings-id (client-id)".
// 2. Certificaten en geheimen > Nieuw clientgeheim: neem de "Waarde" (niet de geheim-id).
// 3. API-machtigingen > Machtiging toevoegen > Microsoft Graph > Toepassingsmachtigingen > Sites.Selected,
//    daarna "Beheerderstoestemming verlenen voor KVT".
// 4. Site-grant (Sites.Selected geeft zonder grant nergens toegang). Eén van beide, als SharePoint/Global admin:
//    a) Graph (bijv. Graph Explorer met Sites.FullControl.All):
//       POST https://graph.microsoft.com/v1.0/sites/kvtnl.sharepoint.com,c1c8a9d4-9d43-433d-9460-8f443afc0525,678213c0-df01-4462-b0a0-528f653a8ce1/permissions
//       {"roles":["read"],"grantedToIdentities":[{"application":{"id":"<CLIENT_ID>","displayName":"Thales"}}]}
//    b) PnP PowerShell:
//       Connect-PnPOnline -Url https://kvtnl.sharepoint.com/sites/<BC Document Repository-url> -Interactive -ClientId <PnP-app-id>
//       Grant-PnPAzureADAppSitePermission -AppId <CLIENT_ID> -DisplayName Thales -Permissions Read
$thalesTenantId = 'TENANT_ID';         // Map-id (tenant-id)
$thalesClientId = 'CLIENT_ID';         // Toepassings-id (client-id)
$thalesClientSecret = 'CLIENT_SECRET'; // Waarde van het clientgeheim (zelf invullen)

// SharePoint-bron: site 'BC Document Repository', bibliotheek 'Equipment'.
$thalesSiteId = 'kvtnl.sharepoint.com,c1c8a9d4-9d43-433d-9460-8f443afc0525,678213c0-df01-4462-b0a0-528f653a8ce1';
$thalesDriveId = 'b!1KnIwUOdPUOUYI9EOvwFJcATgmcB32JEsKBSj2U6jOGlm_IorWP4Srp1K5cz9T1L';

// Tijdsbudget per nightly-run in seconden (de backfill gaat de volgende nacht verder).
$thalesBudgetSeconds = 3600;

// Mímir (zelfde als de andere apps). BC-data wordt alleen in nightly opgehaald (max_age 14400).
$mimirApi = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel, dit is de standaard
$thalesCompany = 'Koninklijke van Twist';

// BC-velden (bevestigd door Ariadne) staan als standaard in thales_lib.php; alleen overschrijven als ze wijzigen:
// $thalesBcComponent = ['entity' => 'AppComponentCard', 'model' => 'Manufacturer_Model', ...];
// $thalesBcItem = ['entity' => 'AppItems', 'key' => 'No', 'description' => 'Description', 'modified' => 'Last_Date_Modified'];
// Extra vertalingen Manufacturer_Code => merknaam (alleen zekere):
// $thalesBrandNames = ['DEUTZ AG' => 'DEUTZ'];
