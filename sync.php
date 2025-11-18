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

/**
 * Retrieves users from MongoDB that are flagged for Entra ID sync.
 * * @param string $host MongoDB host string.
 * @param string $database MongoDB database name.
 * @param string $collection MongoDB collection name.
 * @return \MongoDB\Driver\Cursor The cursor containing the filtered documents.
 */
function getMongoUsers(string $host, string $database, string $collection): \MongoDB\Driver\Cursor {
    try {
        $client = new Client($host);
        $db = $client->selectDatabase($database);
        $collection = $db->selectCollection($collection);

        // --- NEW FILTER IMPLEMENTED HERE ---
        // Find documents where 'syncToEntra' is explicitly true
        $filter = ['syncToEntra' => true];
        return $collection->find($filter);
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
    // Select all properties we might need for comparison and update.
    $queryParameters->select = [
        'id', 'displayName', 'mail', 'givenName', 'companyName',
        'otherMails', 'userPrincipalName', 'usageLocation', 'country'
    ];
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
 * Builds the User object for patching/creating based on MongoDB data.
 * @param array $userData The MongoDB user document data.
 * @param string $userPrincipalName The calculated UPN.
 * @param string $password The password (only used for creation).
 * @return \Microsoft\Graph\Generated\Models\User
 */
function buildUserUpdateObject(
    array $userData,
    string $userPrincipalName,
    ?string $password = null
): User {
    // Standard required fields
    $newDisplayName = ($userData['chosenName'] ?? '') . ' ' . ($userData['familyName'] ?? '');
    $newMailAddress = $userData['email'] ?? null;

    $userObject = new User();
    $userObject->setDisplayName($newDisplayName);

    // mail: Primary SMTP address
    if ($newMailAddress) {
        $userObject->setMail($newMailAddress);
    }

    // --- New Attribute Mappings ---

    // Mongo: givenName -> Entra: givenName
    if (isset($userData['givenName'])) {
        $userObject->setGivenName($userData['givenName']);
    }

    // Mongo: schacHomeOrganization -> Entra: companyName
    if (isset($userData['schacHomeOrganization'])) {
        $userObject->setCompanyName($userData['schacHomeOrganization']);
    }

    // Mongo: email (primary) -> Entra: otherMails (as an array)
    if ($newMailAddress) {
        // otherMails is an array of strings
        $userObject->setOtherMails([$newMailAddress]);
    }

    // --- Hardcoded Entra Fields ---
    $userObject->setUsageLocation("NL");
    $userObject->setCountry("NL");

    // Fields only required for Creation
    if ($password) {
        $passwordProfile = new PasswordProfile();
        $passwordProfile->setPassword($password);
        $passwordProfile->setForceChangePasswordNextSignIn(false);

        $userObject->setAccountEnabled(true);
        $userObject->setUserPrincipalName($userPrincipalName);
        if ($newMailAddress) {
            // mailNickname is typically the alias/part before the @
            $userObject->setMailNickname(explode('@', $newMailAddress)[0]);
        }
        $userObject->setPasswordProfile($passwordProfile);
    }

    return $userObject;
}

/**
 * Updates an existing Entra ID user with a partial User object.
 * @param \Microsoft\Graph\GraphServiceClient $graphServiceClient
 * @param string $userId The ID of the existing user.
 * @param \Microsoft\Graph\Generated\Models\User $userUpdate The User object containing only fields to update.
 * @return bool True on success, false on failure.
 */
function updateEntraUser(
    \Microsoft\Graph\GraphServiceClient $graphServiceClient,
    string $userId,
    User $userUpdate
): bool {
    try {
        // Send a PATCH request to the specific user ID
        $graphServiceClient->users()->byUserId($userId)->patch($userUpdate)->wait();
        echo "   [UPDATE] Successfully updated user ID: {$userId} with new attributes." . PHP_EOL;
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
 * This comparison now only checks against MongoDB users flagged for sync.
 * * @param \Microsoft\Graph\GraphServiceClient $graphServiceClient
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

    // Get ONLY users flagged for sync from MongoDB for the comparison set
    $mongoUsers = getMongoUsers($mongoHost, $mongoDatabase, $mongoCollection);
    $mongoUidSet = [];

    foreach ($mongoUsers as $doc) {
        $userData = (array) $doc;
        if (isset($userData['uid'])) {
            $mongoUidSet[$userData['uid']] = true; // Use associative array for O(1) lookup
        }
    }
    echo "Total unique UIDs flagged for sync in MongoDB: " . count($mongoUidSet) . PHP_EOL;

    $missingCount = 0;
    $logFilePath = 'orphaned_entra_users.txt';
    $logContent = "Entra ID Users Not Found in MongoDB (Source) sync list:\n";
    $logContent .= "NOTE: This only checks against MongoDB users where 'syncToEntra: true'.\n";
    $logContent .= str_repeat('=', 50) . "\n";


    // NOTE: The UPN check here should match the UPN format used in the main loop
    // $domain is not the custom domain, so this part should be verified against
    // how your actual Entra ID domain is configured. Assuming $domain is the main one for now.
    $customUpnDomainPart = "@test.eduid.nl";

    foreach ($entraUPNs as $upn) {
        // If the user's UPN matches the custom format, we extract the UID using that format
        if (str_ends_with($upn, $customUpnDomainPart)) {
            $uid = str_replace($customUpnDomainPart, "", $upn);
        } else {
            // Fallback for other UPN formats, if any exist in Entra
            // This assumes the original domain is used as a fallback if the custom one isn't present
            $uid = str_replace("@".$domain,"",$upn);
        }

        if (!isset($mongoUidSet[$uid])) {
            $logContent .= $upn . "\n";
            $missingCount++;
        }
    }

    if ($missingCount > 0) {
        file_put_contents($logFilePath, $logContent);
        echo "\n⚠️ **ATTENTION:** Found {$missingCount} Entra ID user(s) not present in the MongoDB sync list." . PHP_EOL;
        echo $logContent . PHP_EOL;
        echo "   Details have been saved to: **{$logFilePath}**" . PHP_EOL;
    } else {
        echo "\n✅ All Entra ID users match a record in the MongoDB sync list (based on UPN/UID)." . PHP_EOL;
    }
}

// --- MAIN SYNCHRONIZATION LOOP ---
echo "\n--- Starting User sync (Upsert) from MongoDB to Microsoft Entra ID ---\n";

// This cursor now only contains users with 'syncToEntra: true'
$mongoCursor = getMongoUsers($mongoHost, $mongoDatabase, $mongoCollection);

// Define the specific domain for UPN generation as requested
$customUpnDomain = "@test.eduid.nl";

foreach ($mongoCursor as $mongoDocument) {
    $userData = (array) $mongoDocument;

    // --- New UPN Logic ---
    // Entra userPrincipalName = uid + "@test.eduid.nl" (Using the custom domain)
    $userPrincipalName = ($userData['uid'] ?? null) . $customUpnDomain;

    // These fields are needed for the initial check/skip
    $newDisplayName = ($userData['chosenName'] ?? '') . ' ' . ($userData['familyName'] ?? '');
    $newMailAddress = $userData['email'] ?? null;

    // Fields for comparison check (using default values if not present)
    $newGivenName = $userData['givenName'] ?? null;
    $newCompanyName = $userData['schacHomeOrganization'] ?? null;

    if (!$userPrincipalName || !$newMailAddress) {
        echo "Skipping user: Missing required 'uid' or 'email' fields." . PHP_EOL;
        echo str_repeat('-', 50) . PHP_EOL;
        continue;
    }

    echo "Processing UPN: {$userPrincipalName}" . PHP_EOL;

    $existingUser = findEntraUserByUPN($graphServiceClient, $userPrincipalName);

    if ($existingUser) {
        $needsUpdate = false;

        // --- Comparison Checks for Existing User ---

        // 1. displayName
        if ($existingUser->getDisplayName() !== $newDisplayName) {
            echo "   [CHANGE] Display name needs update: '{$existingUser->getDisplayName()}' -> '{$newDisplayName}'" . PHP_EOL;
            $needsUpdate = true;
        }

        // 2. mail
        if (strtolower($existingUser->getMail() ?? '') !== strtolower($newMailAddress)) {
            echo "   [CHANGE] Primary mail needs update: '{$existingUser->getMail()}' -> '{$newMailAddress}'" . PHP_EOL;
            $needsUpdate = true;
        }

        // 3. givenName
        if (($existingUser->getGivenName() ?? '') !== ($newGivenName ?? '')) {
            echo "   [CHANGE] Given name needs update: '{$existingUser->getGivenName()}' -> '{$newGivenName}'" . PHP_EOL;
            $needsUpdate = true;
        }

        // 4. companyName (schacHomeOrganization)
        if (($existingUser->getCompanyName() ?? '') !== ($newCompanyName ?? '')) {
            echo "   [CHANGE] Company name needs update: '{$existingUser->getCompanyName()}' -> '{$newCompanyName}'" . PHP_EOL;
            $needsUpdate = true;
        }

        // 5. otherMails (Checking if the single mongo email is in Entra's otherMails)
        // The build function will overwrite otherMails with a single entry array. Check is simplified.
        $existingOtherMails = $existingUser->getOtherMails() ?? [];
        if (!in_array($newMailAddress, $existingOtherMails)) {
            echo "   [CHANGE] otherMails needs update (missing primary email)." . PHP_EOL;
            $needsUpdate = true;
        }

        // 6. usageLocation and country (Hardcoded to NL)
        if (($existingUser->getUsageLocation() ?? '') !== "NL") {
            echo "   [CHANGE] usageLocation needs update: '{$existingUser->getUsageLocation()}' -> 'NL'" . PHP_EOL;
            $needsUpdate = true;
        }

        if (($existingUser->getCountry() ?? '') !== "NL") {
            echo "   [CHANGE] country needs update: '{$existingUser->getCountry()}' -> 'NL'" . PHP_EOL;
            $needsUpdate = true;
        }

        if ($needsUpdate) {
            // Build the update object with all new values
            $userUpdate = buildUserUpdateObject($userData, $userPrincipalName);
            updateEntraUser($graphServiceClient, $existingUser->getId(), $userUpdate);
        } else {
            echo "   [SKIP] User exists and no attribute changes detected." . PHP_EOL;
        }

    } else {
        echo "   [CREATE] User not found. Preparing to create new account..." . PHP_EOL;

        $randomPassword = generateRandomPassword(32);

        // Build the full User object for creation
        $newUser = buildUserUpdateObject($userData, $userPrincipalName, $randomPassword);

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