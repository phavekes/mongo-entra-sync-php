<?php

// Schakel het laden van Composer-afhankelijkheden in
require_once 'vendor/autoload.php';

use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Graph\Generated\Users\UsersRequestBuilderGetRequestConfiguration;
use Microsoft\Graph\Generated\Users\UsersRequestBuilderGetQueryParameters;
use Microsoft\Graph\Generated\Models\User;
use MongoDB\Client;
use MongoDB\Driver\CursorInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

$yamlFilePath = 'config.yaml';

$tenantId = '';
$clientId = '';
$clientSecret = '';
$scopes = ['https://graph.microsoft.com/.default'];
$domain = '';
$mongoHost = '';
$mongoDatabase = '';
$mongoCollection = '';

try {
    $config = Yaml::parseFile($yamlFilePath);
    $tenantId = $config['graph']['tenantId'];
    $clientId = $config['graph']['clientId'];
    $clientSecret = $config['graph']['clientSecret'];
    $domain = $config['graph']['domain'];
    $mongoHost = $config['mongo']['host'];
    $mongoDatabase = $config['mongo']['database'];
    $mongoCollection = $config['mongo']['collection'];
} catch (ParseException $exception) {
    printf("Niet mogelijk om het YAML-bestand te parsen: %s\n", $exception->getMessage());
    exit(1);
}

try {
    $tokenRequestContext = new ClientCredentialContext(
        $tenantId,
        $clientId,
        $clientSecret
    );

    $graphServiceClient = new GraphServiceClient($tokenRequestContext, $scopes);

} catch (\Exception $e) {
    die("Fout tijdens Graph client initialisatie: " . $e->getMessage() . PHP_EOL);
}

function getMongoUsers(string $host, string $database, string $collection): CursorInterface
{
    try {
        $client = new Client($host);
        $db = $client->selectDatabase($database);
        $collection = $db->selectCollection($collection);

        // Haalt alleen gebruikers op die gemarkeerd zijn om gesynchroniseerd te worden
        $filter = ['syncToEntra' => true];
        return $collection->find($filter);
    } catch (\Exception $e) {
        die("MongoDB verbinding fout: " . $e->getMessage() . PHP_EOL);
    }
}

function findEntraUserByUPN(
    GraphServiceClient $graphServiceClient,
    string             $userPrincipalName
): ?User {

    $requestConfiguration = new UsersRequestBuilderGetRequestConfiguration();
    $queryParameters = new UsersRequestBuilderGetQueryParameters();

    $queryParameters->filter = "userPrincipalName eq '{$userPrincipalName}'";
    $queryParameters->select = ['id', 'userPrincipalName'];
    $requestConfiguration->queryParameters = $queryParameters;

    try {
        $response = $graphServiceClient->users()->get($requestConfiguration)->wait();
        $users = $response->getValue();

        if (count($users) > 0) {
            return $users[0];
        }
        return null;

    } catch (\Exception $e) {
        echo "Fout bij het zoeken naar gebruiker met UPN {$userPrincipalName}: " . $e->getMessage() . PHP_EOL;
        return null;
    }
}

function deleteEntraUser(
    GraphServiceClient $graphServiceClient,
    string             $userId
): bool {
    try {
        // Deletes the user from Entra ID
        $graphServiceClient->users()->byUserId($userId)->delete()->wait();
        echo "   [VERWIJDERD] Gebruiker ID: {$userId} succesvol verwijderd uit Entra ID." . PHP_EOL;
        return true;
    } catch (\Exception $e) {
        // Opmerking: Entra ID API geeft een 404 (Not Found) terug als de gebruiker niet bestaat,
        // wat hier een uitzondering zou veroorzaken. We loggen dit als een fout.
        echo "   [FAALDE] Fout bij het verwijderen van gebruiker ID {$userId}: " . $e->getMessage() . PHP_EOL;
        return false;
    }
}


// --- HOOFD VERWIJDERINGSLOOP ---
echo "\n--- Start Gebruiker Verwijdering uit Microsoft Entra ID ---\n";
echo "LET OP: Dit script zal **ALLE** Entra ID-gebruikers verwijderen die overeenkomen met de records in MongoDB waar 'syncToEntra: true'." . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;


$mongoCursor = getMongoUsers($mongoHost, $mongoDatabase, $mongoCollection);
$customUpnDomain = "@" . $domain;
$deleteCount = 0;

foreach ($mongoCursor as $mongoDocument) {
    $userData = (array) $mongoDocument;

    // Gebruik 'chosenLoginName' om de UPN te construeren
    $loginName = $userData['chosenLoginName'] ?? null;

    if (!$loginName) {
        echo "Overslaan: Ontbrekend 'chosenLoginName' veld in MongoDB record." . PHP_EOL;
        continue;
    }

    $userPrincipalName = $loginName . $customUpnDomain;

    echo "Controleren op UPN: {$userPrincipalName}" . PHP_EOL;

    $existingUser = findEntraUserByUPN($graphServiceClient, $userPrincipalName);

    if ($existingUser) {
        echo "   [G मैचT] Gebruiker gevonden in Entra ID." . PHP_EOL;
        $userId = $existingUser->getId();

        // Voer de verwijdering uit
        if (deleteEntraUser($graphServiceClient, $userId)) {
            $deleteCount++;
        }

    } else {
        echo "   [OVERSLAAN] Gebruiker niet gevonden in Entra ID (mogelijk reeds verwijderd of nooit aangemaakt)." . PHP_EOL;
    }
    echo str_repeat('-', 50) . PHP_EOL;
}

echo "\n--- Verwijdering Voltooid ---\n";
echo "Totaal aantal gebruikers geprobeerd te verwijderen: " . $deleteCount . PHP_EOL;
?>