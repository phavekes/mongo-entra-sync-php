<?php

// Enable loading of Composer dependencies
require_once 'vendor/autoload.php';

use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Graph\Generated\Users\UsersRequestBuilderGetRequestConfiguration;
use Microsoft\Graph\Generated\Users\UsersRequestBuilderGetQueryParameters;
use Microsoft\Graph\Generated\Models\User;
use Microsoft\Graph\Generated\Models\PasswordProfile;
use Microsoft\Graph\Generated\Users\UsersGetResponse;
use MongoDB\Client;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// add config to the yaml file
$yamlFilePath = 'config.yaml';

// Read config
$tenantId = '';
$clientId = '';
$clientSecret = '';
$scopes = ['https://graph.microsoft.com/.default']; // Default scope for client credentials flow
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
    printf("Unable to parse the YAML file: %s\n", $exception->getMessage());
}

// Initialize Graph Service Client ---
try {
    // Context for Client Credentials flow (App-only authentication)
    $tokenRequestContext = new ClientCredentialContext(
        $tenantId,
        $clientId,
        $clientSecret
    );

    // Initialize the GraphServiceClient
    $graphServiceClient = new GraphServiceClient($tokenRequestContext, $scopes);

} catch (\Exception $e) {
    die("Error during Graph client initialization: " . $e->getMessage() . PHP_EOL);
}

function getMongoUsers(string $host, string $database, string $collection): \MongoDB\Driver\Cursor {
    try {
        $client = new Client($host);
        $db = $client->selectDatabase($database);
        $collection = $db->selectCollection($collection);

        // Find all documents in the 'users' collection
        return $collection->find();
    } catch (\Exception $e) {
        die("MongoDB connection error: " . $e->getMessage() . PHP_EOL);
    }
}

function generateRandomPassword(int $length = 32): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_+=';
    $password = '';
    $maxIndex = strlen($chars) - 1;

    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $maxIndex)];
    }
    return $password;
}

/**
 * Finds a user in Entra ID by User Principal Name (UPN).
 * @param \Microsoft\Graph\GraphServiceClient $graphServiceClient
 * @param string $userPrincipalName
 * @return \Microsoft\Graph\Generated\Models\User|null The User object if found, otherwise null.
 */
function findEntraUserByUPN(
    \Microsoft\Graph\GraphServiceClient $graphServiceClient,
    string $userPrincipalName
): ?User {

    $requestConfiguration = new UsersRequestBuilderGetRequestConfiguration();
    $queryParameters = new UsersRequestBuilderGetQueryParameters();

    // Filter by UPN (uid)
    $queryParameters->filter = "userPrincipalName eq '{$userPrincipalName}'";
    $queryParameters->select = ['id', 'displayName', 'mail']; // Select necessary properties
    $requestConfiguration->queryParameters = $queryParameters;

    try {
        $response = $graphServiceClient->users()->get($requestConfiguration)->wait();

        // The value array will contain the list of users found by the filter.
        $users = $response->getValue();

        if (count($users) > 0) {
            // Return the first user found (should only be one if UPN is unique)
            return $users[0];
        }
        return null;

    } catch (\Exception $e) {
        // Log the search error but continue
        echo "Error searching for user {$userPrincipalName}: " . $e->getMessage() . PHP_EOL;
        return null;
    }
}

/**
 * Updates the mail and displayName of an existing Entra ID user.
 * @param \Microsoft\Graph\GraphServiceClient $graphServiceClient
 * @param string $userId The ID of the existing user.
 * @param string $newDisplayName
 * @param string $newMailAddress
 * @return bool True on success, false on failure.
 */
function updateEntraUser(
    \Microsoft\Graph\GraphServiceClient $graphServiceClient,
    string $userId,
    string $newDisplayName,
    string $newMailAddress
): bool {

    $userUpdate = new User();
    $userUpdate->setDisplayName($newDisplayName);
    $userUpdate->setMail($newMailAddress);

    try {
        // Send a PATCH request to the specific user ID
        $graphServiceClient->users()->byUserId($userId)->patch($userUpdate)->wait();
        echo "   [UPDATE] Updated displayName and mail for user ID: {$userId}" . PHP_EOL;
        return true;
    } catch (\Exception $e) {
        echo "   [UPDATE FAILED] Error updating user ID {$userId}: " . $e->getMessage() . PHP_EOL;
        return false;
    }
}

/**
 * Retrieves ALL User Principal Names (UPNs) from Entra ID using pagination.
 * * @param \Microsoft\Graph\GraphServiceClient $graphServiceClient
 * @return array<string> An array of all UPNs found in Entra ID.
 */
function getAllEntraUPNs(\Microsoft\Graph\GraphServiceClient $graphServiceClient): array {
    $allUPNs = [];
    $nextLink = null;
    $pageCount = 0;

    // Initial request configuration
    $requestConfiguration = new UsersRequestBuilderGetRequestConfiguration();
    $queryParameters = new UsersRequestBuilderGetQueryParameters();
    $queryParameters->select = ['userPrincipalName', 'id'];
    $requestConfiguration->queryParameters = $queryParameters;

    echo "Fetching all Entra ID users (may take time for large directories)..." . PHP_EOL;

    do {
        try {
            /** @var UsersGetResponse $usersResponse */
            if ($nextLink) {
                echo "   -> Paging: Fetching page " . ($pageCount + 1) . " using nextLink..." . PHP_EOL;
                break;

            } else {
                $usersResponse = $graphServiceClient->users()->get($requestConfiguration)->wait();
            }

            /** @var \Microsoft\Graph\Generated\Models\User[] $users */
            $users = $usersResponse->getValue() ?? [];
            foreach ($users as $user) {
                if ($user->getUserPrincipalName()) {
                    $allUPNs[] = $user->getUserPrincipalName();
                }
            }

            $nextLink = $usersResponse->getOdataNextLink();
            $pageCount++;

        } catch (\Exception $e) {
            echo "Error fetching Entra users for comparison (Page {$pageCount}): " . $e->getMessage() . PHP_EOL;
            $nextLink = null; // Stop the loop on error
        }

    } while ($nextLink !== null);

    echo "Successfully fetched {$pageCount} page(s), total UPNs collected: " . count($allUPNs) . PHP_EOL;
    return $allUPNs;
}

/**
 * Compares Entra ID users against MongoDB users and logs the missing ones.
 * @param \Microsoft\Graph\GraphServiceClient $graphServiceClient
 * @param string $mongoHost
 * @param string $mongoDatabase
 * @param string $mongoCollection
 */
function logMissingEntraUsers(
    \Microsoft\Graph\GraphServiceClient $graphServiceClient,
    string $mongoHost,
    string $mongoDatabase,
    string $mongoCollection,
    string $domain
): void {

    echo "\n--- Checking for Entra Users Not in MongoDB (Orphaned Accounts) ---\n";

    $entraUPNs = getAllEntraUPNs($graphServiceClient);
    echo "Total UPNs found in Entra ID: " . count($entraUPNs) . PHP_EOL;

    $mongoUsers = getMongoUsers($mongoHost, $mongoDatabase, $mongoCollection);
    $mongoUidSet = [];

    foreach ($mongoUsers as $doc) {
        $userData = (array) $doc;
        if (isset($userData['uid'])) {
            $mongoUidSet[$userData['uid']] = true; // Use associative array for O(1) lookup
        }
    }
    echo "Total unique UIDs found in MongoDB: " . count($mongoUidSet) . PHP_EOL;

    $missingCount = 0;
    $logFilePath = 'orphaned_entra_users.txt';
    $logContent = "Entra ID Users Not Found in MongoDB (Source):\n";
    $logContent .= str_repeat('=', 50) . "\n";


    foreach ($entraUPNs as $upn) {
        $upn=str_replace("@".$domain,"",$upn);
        if (!isset($mongoUidSet[$upn])) {
            $logContent .= $upn . "\n";
            $missingCount++;
        }
    }

    if ($missingCount > 0) {
        file_put_contents($logFilePath, $logContent);
        echo "\n⚠️ **ATTENTION:** Found {$missingCount} Entra ID user(s) not present in MongoDB." . PHP_EOL;
        echo $logContent . PHP_EOL;
        echo "   Details have been saved to: **{$logFilePath}**" . PHP_EOL;
    } else {
        echo "\n✅ All Entra ID users match a record in MongoDB (based on UPN/UID)." . PHP_EOL;
    }
}

echo "\n--- Starting User sync (Upsert) from MongoDB to Microsoft Entra ID ---\n";

$mongoCursor = getMongoUsers($mongoHost, $mongoDatabase, $mongoCollection);

foreach ($mongoCursor as $mongoDocument) {
    $userData = (array) $mongoDocument;

    $userPrincipalName = $userData['uid'] ."@".$domain ?? null;
    $newDisplayName = ($userData['chosenName'] ?? '') . ' ' . ($userData['familyName'] ?? '');
    $newMailAddress = $userData['email'] ?? null;

    if (!$userPrincipalName || !$newMailAddress) {
        echo "Skipping user: Missing required 'uid' or 'email' fields." . PHP_EOL;
        echo str_repeat('-', 50) . PHP_EOL;
        continue;
    }

    echo "Processing UPN: {$userPrincipalName}" . PHP_EOL;

    $existingUser = findEntraUserByUPN($graphServiceClient, $userPrincipalName);

    if ($existingUser) {
        $needsUpdate = false;

        if ($existingUser->getDisplayName() !== $newDisplayName) {
            echo "   [CHANGE] Display name needs update: '{$existingUser->getDisplayName()}' -> '{$newDisplayName}'" . PHP_EOL;
            $needsUpdate = true;
        }
        if (strtolower($existingUser->getMail() ?? '') !== strtolower($newMailAddress)) {
            echo "   [CHANGE] Mail address needs update: '{$existingUser->getMail()}' -> '{$newMailAddress}'" . PHP_EOL;
            $needsUpdate = true;
        }

        if ($needsUpdate) {
            updateEntraUser($graphServiceClient, $existingUser->getId(), $newDisplayName, $newMailAddress);
        } else {
            echo "   [SKIP] User exists and no changes detected." . PHP_EOL;
        }

    } else {
        echo "   [CREATE] User not found. Preparing to create new account..." . PHP_EOL;

        $randomPassword = generateRandomPassword(32);

        $passwordProfile = new PasswordProfile();
        $passwordProfile->setPassword($randomPassword);
        $passwordProfile->setForceChangePasswordNextSignIn(false);

        $newUser = new User();
        $newUser->setAccountEnabled(true);
        $newUser->setDisplayName($newDisplayName);
        $newUser->setUserPrincipalName($userPrincipalName);
        $newUser->setMail($newMailAddress);
        $newUser->setMailNickname(explode('@', $newMailAddress)[0]);
        $newUser->setPasswordProfile($passwordProfile);

        try {
            $createdUser = $graphServiceClient->users()->post($newUser)->wait();
            echo "   ✅ Created user ID: " . $createdUser->getId() . " successfully." . PHP_EOL;
            echo "   [Password]: " . $randomPassword . " (MUST be saved securely!)" . PHP_EOL;
        } catch (\Exception $e) {
            echo "   ❌ Failed to create user {$userPrincipalName}: " . $e->getMessage() . PHP_EOL;
        }
    }
    echo str_repeat('-', 50) . PHP_EOL;
}

echo "--- Upsert Complete ---\n";

logMissingEntraUsers(
    $graphServiceClient,
    $mongoHost,
    $mongoDatabase,
    $mongoCollection,
    $domain
);

?>