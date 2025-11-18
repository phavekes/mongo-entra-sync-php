<?php

// Enable loading of Composer dependencies
require_once 'vendor/autoload.php';

use MongoDB\Client;
use Ramsey\Uuid\Uuid; // Library to easily generate UUID v4

// You may need to install this library via Composer if it's not present:
// composer require ramsey/uuid

// --- MongoDB Configuration ---
$mongoHost = 'mongodb://localhost:27017';
$mongoDatabase = 'myconext';
$mongoCollection = 'users';
$userCount = 100;
$emailDomain = '@havekes.eu';
$emailPrefix = 'peter+';

// --- Name Pools for Random Generation ---
$firstNames = ['Alice', 'Bob', 'Charlie', 'Dana', 'Eve', 'Frank', 'Grace', 'Henry', 'Ivy', 'Jack', 'Peter', 'Ines'];
$lastNames = ['Smith', 'Jones', 'Williams', 'Brown', 'Davis', 'Miller', 'Wilson', 'Moore', 'Taylor', 'Anderson', 'Havekes', 'Clijsters', 'Duits'];

// --- Connect to MongoDB ---
try {
    $client = new Client($mongoHost);
    $db = $client->selectDatabase($mongoDatabase);
    $collection = $db->selectCollection($mongoCollection);

    echo "Connected to MongoDB database '{$mongoDatabase}'.\n";

} catch (\Exception $e) {
    die("MongoDB connection error: " . $e->getMessage() . "\n");
}

$documentsToInsert = [];

// --- Generate Random User Data ---
echo "Generating {$userCount} random users...\n";

for ($i = 1; $i <= $userCount; $i++) {
    // Generate UUIDv4 for uid
    $uid = Uuid::uuid4()->toString();

    // Generate Display Name parts
    $chosenName = $firstNames[array_rand($firstNames)];
    $givenName = $firstNames[array_rand($firstNames)];
    $familyName = $lastNames[array_rand($lastNames)];
    $schacHomeOrganization = "eduid.nl";

    // Construct Email/User Principal Name (UPN)
    // The UPN pattern requested is peter+<number>@havekes.eu
    $emailAddress = $emailPrefix . $i . $emailDomain;

    $documentsToInsert[] = [
        // This will be used for userPrincipalName in Entra ID
        'uid' => $uid,

        // These will be combined for displayName
        'chosenName' => $chosenName,
        'givenName' => $givenName,
        'familyName' => $familyName,
        'schacHomeOrganization' => $schacHomeOrganization,

        // This will be used for mailAddress in Entra ID
        'email' => $emailAddress,

        'createdAt' => new MongoDB\BSON\UTCDateTime()
    ];
}

// --- Insert Documents ---
echo "Inserting documents into '{$mongoCollection}' collection...\n";

try {
    $result = $collection->insertMany($documentsToInsert);

    echo "✅ Successfully inserted {$result->getInsertedCount()} documents.\n";

} catch (\Exception $e) {
    echo "❌ Error during insertion: " . $e->getMessage() . "\n";
}

?>