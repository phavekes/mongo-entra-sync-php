<?php

require_once 'vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

$yamlFilePath = 'config.yaml';
$userCount = 100;
$emailDomain = '@example.com';
$emailPrefix = 'private+';

// Read config
$mongoHost = '';
$mongoDatabase = '';
$mongoCollection = '';

try {
    $config = Yaml::parseFile($yamlFilePath);
    $mongoHost = $config['mongo']['host'];
    $mongoDatabase = $config['mongo']['database'];
    $mongoCollection = $config['mongo']['collection'];
} catch (ParseException $exception) {
    printf("Unable to parse the YAML file: %s\n", $exception->getMessage());
}

$firstNames = [
    "Sanne",
    "Thijs",
    "Lieke",
    "Jesse",
    "Emma",
    "Daan",
    "Sophie",
    "Thomas",
    "Fleur",
    "Lucas",
    "Elin",
    "Milan",
    "Noor",
    "Finn",
    "Lotte",
    "Sem",
    "Lynn",
    "Timo",
    "Julia",
    "Gijs",
    "Zara",
    "Hugo",
    "Eva",
    "Niek",
    "Bo",
    "Ruben",
    "Roos",
    "Cas",
    "Isa",
    "Jurre",
    "Maud",
    "Dean",
    "Jara",
    "Stijn",
    "Tess",
    "Mees",
    "Vera",
    "Boris",
    "Lana",
    "Rick",
    "Puck",
    "Wouter",
    "Stella",
    "Pepijn",
    "Indy",
    "Floris",
    "Merel",
    "Joris",
    "Nina",
    "Rens"
];

$lastNames = [
    "Jansen",
    "De Vries",
    "Van den Berg",
    "Bakker",
    "Van Dijk",
    "Visser",
    "Smit",
    "Meijer",
    "De Boer",
    "De Groot",
    "Mulder",
    "Hendriks",
    "Bos",
    "Vos",
    "Peters",
    "Pronk",
    "Jacobs",
    "Van der Meer",
    "Evers",
    "Van Vliet",
    "Pietersen",
    "Klaassen",
    "Bouwman",
    "Driessen",
    "Dekker",
    "Van de Velde",
    "Schouten",
    "Willems",
    "Maas",
    "Koster",
    "Timmermans",
    "Geurts",
    "Van Leeuwen",
    "Vermeulen",
    "Huisman",
    "Jonker",
    "Kroes",
    "Brouwer",
    "Van Dongen",
    "Kuipers",
    "De Ruiter",
    "Postma",
    "Zijlstra",
    "Hoekstra",
    "Veldhuis",
    "Schuitemaker",
    "De Jong",
    "Steenbergen",
    "Van der Horst",
    "Veenstra"
];

$affiliationTypes = ['student', 'employee', 'affiliate', 'pre-student', 'faculty', 'member'];
$schoolDomains = ['godebaldcollege.nl', 'uu.nl', 'surf.nl', 'vu.nl', 'hva.nl', 'utwente.nl'];

// New pool of random words
$randomWords = ['alpha', 'beta', 'gamma', 'delta', 'echo', 'foxtrot', 'golf', 'hotel', 'india', 'juliet', 'kilo', 'lima', 'mike', 'november'];

try {
    $client = new Client($mongoHost);
    $db = $client->selectDatabase($mongoDatabase);
    $collection = $db->selectCollection($mongoCollection);

    echo "Connected to MongoDB database '{$mongoDatabase}'.\n";

} catch (\Exception $e) {
    die("MongoDB connection error: " . $e->getMessage() . "\n");
}

echo "\n--- Drop Operation ---\n";
try {
    $dropResult = $collection->deleteMany([]);
    echo "🗑️ Successfully dropped **{$dropResult->getDeletedCount()}** existing documents from '{$mongoCollection}'.\n";
} catch (\Exception $e) {
    echo "❌ Error during drop operation: " . $e->getMessage() . "\n";
}
echo "----------------------\n";

function generateRandomAffiliation(array $affiliationTypes, array $schoolDomains): array
{
    $count = rand(1, 3);
    $affiliations = [];
    $usedDomains = [];

    for ($i = 0; $i < $count; $i++) {
        $type = $affiliationTypes[array_rand($affiliationTypes)];

        do {
            $domain = $schoolDomains[array_rand($schoolDomains)];
        } while (in_array($domain, $usedDomains));

        $usedDomains[] = $domain;

        $affiliations[] = "{$type}@{$domain}";
    }

    return [
        'linkedAccounts' => [
            [
                'eduPersonAffiliations' => $affiliations
            ]
        ]
    ];
}

$documentsToInsert = [];

echo "Generating {$userCount} random users...\n";

for ($i = 1; $i <= $userCount; $i++) {
    $uid = Uuid::uuid4()->toString();

    $chosenName = $firstNames[array_rand($firstNames)];
    $givenName = $firstNames[array_rand($firstNames)];
    $familyName = $lastNames[array_rand($lastNames)];
    $schacHomeOrganization = "eduid.nl";

    $emailAddress = $emailPrefix . $i . $emailDomain;

    $affiliationData = generateRandomAffiliation($affiliationTypes, $schoolDomains);

    // Generate unique counter suffix
    $uniqueSuffix = str_pad($i, 3, '0', STR_PAD_LEFT);

    // Pick a random word
    $randWord = $randomWords[array_rand($randomWords)];

    // Choose a random login name pattern (1 to 9 - 4 new patterns added)
    $pattern = rand(1, 9);
    $loginPrefix = '';

    switch ($pattern) {
        case 1:
            // Pattern 1: First Initial + Last Name (e.g., psmith001)
            $loginPrefix = strtolower(substr($givenName, 0, 1) . $familyName);
            break;
        case 2:
            // Pattern 2: Full First Name (e.g., peter001)
            $loginPrefix = strtolower($givenName);
            break;
        case 3:
            // Pattern 3: Full Last Name (e.g., havekes001)
            $loginPrefix = strtolower($familyName);
            break;
        case 4:
            // Pattern 4: Last Name + First Initial (e.g., smithp001)
            $loginPrefix = strtolower($familyName . substr($givenName, 0, 1));
            break;
        case 5:
            // Pattern 5: Completely Random Word (e.g., alpha001)
            $loginPrefix = $randWord;
            break;
        case 6:
            // NEW Pattern 6: Random Word + Underscore + Last Name (e.g., alpha_smith001)
            $loginPrefix = strtolower($randWord . '_' . $familyName);
            break;
        case 7:
            // NEW Pattern 7: First Name + Hyphen + Random Word (e.g., peter-alpha001)
            $loginPrefix = strtolower($givenName . '-' . $randWord);
            break;
        case 8:
            // NEW Pattern 8: Last Name + Underscore + First Initial (e.g., smith_p001)
            $loginPrefix = strtolower($familyName . '_' . substr($givenName, 0, 1));
            break;
        case 9:
            // NEW Pattern 9: First Initial + Hyphen + Last Name (e.g., p-smith001)
            $loginPrefix = strtolower(substr($givenName, 0, 1) . '-' . $familyName);
            break;
    }

    // Combine prefix and unique counter
    $chosenLoginName = $loginPrefix . $uniqueSuffix;

    $documentsToInsert[] = array_merge(
        [
            'uid' => $uid,

            'chosenName' => $chosenName,
            'givenName' => $givenName,
            'familyName' => $familyName,
            'schacHomeOrganization' => $schacHomeOrganization,

            'email' => $emailAddress,

            'syncToEntra' => true,

            'chosenLoginName' => $chosenLoginName,

            'createdAt' => new UTCDateTime()
        ],
        $affiliationData
    );
}

echo "\nInserting documents into '{$mongoCollection}' collection...\n";

try {
    $result = $collection->insertMany($documentsToInsert);

    echo "✅ Successfully inserted **{$result->getInsertedCount()}** documents.\n";

} catch (\Exception $e) {
    echo "❌ Error during insertion: " . $e->getMessage() . "\n";
}

?>