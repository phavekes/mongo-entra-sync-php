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
use Microsoft\Graph\Generated\Users\UsersRequestBuilder;
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
$target_emails="";

try {
    $config = Yaml::parseFile($yamlFilePath);
    $tenantId = $config['graph']['tenantId'];
    $clientId = $config['graph']['clientId'];
    $clientSecret = $config['graph']['clientSecret'];
    $domain = $config['graph']['domain'];
    $mongoHost = $config['mongo']['host'];
    $mongoDatabase = $config['mongo']['database'];
    $mongoCollection = $config['mongo']['collection'];
    $target_emails = $config['mails'];
} catch (ParseException $exception) {
    printf("Unable to parse the YAML file: %s\n", $exception->getMessage());
}

try {
    $tokenRequestContext = new ClientCredentialContext(
        $tenantId,
        $clientId,
        $clientSecret
    );

    $graphServiceClient = new GraphServiceClient($tokenRequestContext, $scopes);

} catch (\Exception $e) {
    die("Error during Graph client initialization: " . $e->getMessage() . PHP_EOL);
}

function getMongoUsers(string $host, string $database, string $collection): CursorInterface
{
    global $target_emails;
    try {
        $client = new Client($host);
        $db = $client->selectDatabase($database);
        $collection = $db->selectCollection($collection);

        // Include the 'uid' field in the projection
        //$filter = ['syncToEntra' => true];
        $filter = [
            'email' => [
                '$in' => $target_emails
            ]
        ];
        //$options = ['projection' => ['_id' => 1, 'uid' => 1, 'email' => 1, 'chosenName' => 1, 'familyName' => 1, 'givenName' => 1, 'schacHomeOrganization' => 1, 'linkedAccounts' => 1]];
        //$options = ['projection' => ['_id' => 1, 'uid' => 1, 'chosenLoginName' => 1, 'email' => 1, 'chosenName' => 1, 'familyName' => 1, 'givenName' => 1, 'schacHomeOrganization' => 1, 'linkedAccounts' => 1]];
        return $collection->find($filter);
        //xreturn $collection->find($filter, $options);
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

function findEntraUserByUPN(
    GraphServiceClient $graphServiceClient,
    string             $userPrincipalName,
    string             $customAttributeName
): ?User {

    $requestConfiguration = new UsersRequestBuilderGetRequestConfiguration();
    $queryParameters = new UsersRequestBuilderGetQueryParameters();

    // Include 'onPremisesImmutableId' in the select statement
    $queryParameters->filter = "userPrincipalName eq '{$userPrincipalName}'";
    $queryParameters->select = [
        'id', 'displayName', 'mail', 'givenName', 'surname', 'companyName',
        'otherMails', 'userPrincipalName', 'usageLocation', 'country',
        'onPremisesImmutableId', // <-- MODIFIED: Select the immutable ID
        $customAttributeName
    ];
    $requestConfiguration->queryParameters = $queryParameters;

    try {
        $response = $graphServiceClient->users()->get($requestConfiguration)->wait();

        $users = $response->getValue();

        if (count($users) > 0) {
            return $users[0];
        }
        return null;

    } catch (\Exception $e) {
        echo "Error searching for user {$userPrincipalName}: " . $e->getMessage() . PHP_EOL;
        return null;
    }
}

/**
 * Builds the Microsoft Graph User object for creation or update.
 *
 * @param array $userData The user data array from MongoDB.
 * @param string $userPrincipalName The target UPN.
 * @param string $customAttributeName The custom extension attribute name for affiliations.
 * @param string|null $sourceAnchor The MongoDB 'uid' string to be used as OnPremisesImmutableId (used ONLY for creation).
 * @param string|null $password The password for new user creation.
 * @return User The configured Microsoft Graph User object.
 */
function buildUserUpdateObject(
    array $userData,
    string $userPrincipalName,
    string $customAttributeName,
    ?string $sourceAnchor = null,
    ?string $password = null
): User {
    $newDisplayName = ($userData['chosenName'] ?? '') . ' ' . ($userData['familyName'] ?? '');
    $newMailAddress = $userData['email'] ?? null;
    $newFamilyName = $userData['familyName'] ?? null;

    $userObject = new User();

    // NOTE: This property should only be set during NEW user creation, as it is immutable.
    if ($sourceAnchor) {
        $userObject->setOnPremisesImmutableId($sourceAnchor);
    }

    $userObject->setDisplayName($newDisplayName);

    if ($newMailAddress) {
        $userObject->setMail($newMailAddress);
    }

    if (isset($userData['givenName'])) {
        $userObject->setGivenName($userData['givenName']);
    }

    if ($newFamilyName !== null) {
        $userObject->setSurname($newFamilyName);
    }

    if (isset($userData['schacHomeOrganization'])) {
        $userObject->setCompanyName($userData['schacHomeOrganization']);
    }

    if ($newMailAddress) {
        // Entra ID requires otherMails to be an array of strings
        $userObject->setOtherMails([$newMailAddress]);
    }

    $eduPersonAffiliations = null;
    $customData = [];

    if (isset($userData['linkedAccounts']) ) {
        foreach ($userData['linkedAccounts'] as $linkedAccount) {
            $linkedAccount = (array) $linkedAccount;
            if (isset($linkedAccount['eduPersonAffiliations']) ) {
                $affiliations= (array) $linkedAccount['eduPersonAffiliations'];
                $eduPersonAffiliations = implode(';', $affiliations);
                break;
            }
        }
    }

    if ($eduPersonAffiliations !== null) {
        $customData[$customAttributeName] = $eduPersonAffiliations;
    }

    if (!empty($customData)) {
        $userObject->setAdditionalData($customData);
    }

    $userObject->setUsageLocation("NL");
    $userObject->setCountry("NL");

    if ($password) {
        $passwordProfile = new PasswordProfile();
        $passwordProfile->setPassword($password);
        $passwordProfile->setForceChangePasswordNextSignIn(false);

        $userObject->setAccountEnabled(true);
        $userObject->setUserPrincipalName($userPrincipalName);
        if ($newMailAddress) {
            $userObject->setMailNickname(explode('@', $newMailAddress)[0]);
        }
        $userObject->setPasswordProfile($passwordProfile);
    }

    return $userObject;
}

function updateEntraUser(
    GraphServiceClient $graphServiceClient,
    string             $userId,
    User               $userUpdate
): bool {
    try {
        $graphServiceClient->users()->byUserId($userId)->patch($userUpdate)->wait();
        echo "   [UPDATE] Successfully updated user ID: {$userId} with new attributes." . PHP_EOL;
        return true;
    } catch (\Exception $e) {
        echo "   [UPDATE FAILED] Error updating user ID {$userId}: " . $e->getMessage() . PHP_EOL;
        return false;
    }
}

function getAllEntraUPNs(GraphServiceClient $graphServiceClient): array {
    $allUPNs = [];
    $pageCount = 0;

    $requestBuilder = $graphServiceClient->users();

    $requestConfiguration = new UsersRequestBuilderGetRequestConfiguration();
    $queryParameters = new UsersRequestBuilderGetQueryParameters();
    $queryParameters->select = ['userPrincipalName', 'id'];
    $requestConfiguration->queryParameters = $queryParameters;

    echo "Fetching all Entra ID users (may take time for large directories)..." . PHP_EOL;

    try {
        $usersResponse = $requestBuilder->get($requestConfiguration)->wait();

        while (true) {
            $pageCount++;

            $users = $usersResponse->getValue() ?? [];
            foreach ($users as $user) {
                if ($user->getUserPrincipalName()) {
                    $allUPNs[] = $user->getUserPrincipalName();
                }
            }

            $nextLink = $usersResponse->getOdataNextLink();

            if ($nextLink === null) {
                break;
            }

            echo "   -> Paging: Fetching page " . ($pageCount + 1) . " using nextLink..." . PHP_EOL;

            $usersResponse = $requestBuilder
                ->withUrl($nextLink)
                ->get(null)
                ->wait();

        }

    } catch (\Exception $e) {
        echo "Error fetching Entra users for comparison (Page {$pageCount}): " . $e->getMessage() . PHP_EOL;
    }

    echo "Successfully fetched {$pageCount} page(s), total UPNs collected: " . count($allUPNs) . PHP_EOL;
    return $allUPNs;
}

function logMissingEntraUsers(
    GraphServiceClient $graphServiceClient,
    string             $mongoHost,
    string             $mongoDatabase,
    string             $mongoCollection,
    string             $domain
): void {

    echo "\n--- Checking for Entra Users Not in MongoDB (Orphaned Accounts) ---\n";

    $entraUPNs = getAllEntraUPNs($graphServiceClient);
    echo "Total UPNs found in Entra ID: " . count($entraUPNs) . PHP_EOL;

    // Use the getMongoUsers function to ensure consistency
    $mongoUsers = getMongoUsers($mongoHost, $mongoDatabase, $mongoCollection);
    $mongoLoginNameSet = [];
    $mongoUidSet = [];

    foreach ($mongoUsers as $doc) {
        $userData = (array) $doc;
        if (isset($userData['uid'])) {
            $mongoUidSet[$userData['uid']] = true;
        }
    }
    echo "Total unique UIDs flagged for sync in MongoDB: " . count($mongoUidSet) . PHP_EOL;

    $missingCount = 0;
    $logFilePath = 'orphaned_entra_users.txt';
    $logContent = "Entra ID Users Not Found in MongoDB (Source) sync list:\n";
    $logContent .= "NOTE: This only checks against MongoDB users where 'syncToEntra: true'.\n";
    $logContent .= str_repeat('=', 50) . "\n";


    $customUpnDomainPart = "@" . $domain;

    foreach ($entraUPNs as $upn) {
        if (str_ends_with($upn, $customUpnDomainPart)) {
            $loginName = str_replace($customUpnDomainPart, "", $upn);
        } else {
            $parts = explode('@', $upn, 2);
            $loginName = $parts[0];
        }

        if (!isset($mongoLoginNameSet[$loginName])) {
            $logContent .= $upn . "\n";
            $missingCount++;
        }
    }

    if ($missingCount > 0) {
        file_put_contents($logFilePath, $logContent);
        echo "\nATTENTION: Found {$missingCount} Entra ID user(s) not present in the MongoDB sync list." . PHP_EOL;
        echo $logContent . PHP_EOL;
        echo "   Details have been saved to: {$logFilePath}" . PHP_EOL;
    } else {
        echo "\nAll Entra ID users match a record in the MongoDB sync list (based on UPN/chosenLoginName)." . PHP_EOL;
    }
}

// --- MAIN SYNCHRONIZATION LOOP ---
echo "\n--- Starting User sync (Upsert) from MongoDB to Microsoft Entra ID ---\n";

$mongoCursor = getMongoUsers($mongoHost, $mongoDatabase, $mongoCollection);

$customAffiliationAttribute = 'extension_53ae2cfceab542d79c2e1d7f826ef431_eduAffiliations';
$customUpnDomain = "@" . $domain;

foreach ($mongoCursor as $mongoDocument) {
    $userData = (array) $mongoDocument;

    // 💡 MODIFIED: Retrieve the unique ID from the 'uid' field to use as Source Anchor
    $source_anchor_uid = $userData['uid'] ?? null;

    if (!$source_anchor_uid) {
        echo "Skipping user: MongoDB **'uid'** field is missing or invalid in the document." . PHP_EOL;
        echo str_repeat('-', 50) . PHP_EOL;
        continue;
    }

//    $loginName = $userData['chosenLoginName'] ?? null;
//    $userPrincipalName = $loginName . $customUpnDomain;
    $userPrincipalName = ($userData['uid'] ?? null) . $customUpnDomain;

    $newDisplayName = ($userData['chosenName'] ?? '') . ' ' . ($userData['familyName'] ?? '');
    $newMailAddress = $userData['email'] ?? null;
    $newGivenName = $userData['givenName'] ?? null;
    $newFamilyName = $userData['familyName'] ?? null;
    $newCompanyName = $userData['schacHomeOrganization'] ?? null;

    $allAffiliations = [];
    if (isset($userData['linkedAccounts'])) {
        foreach ($userData['linkedAccounts'] as $linkedAccount) {
            $linkedAccount = (array) $linkedAccount;
            if (isset($linkedAccount['eduPersonAffiliations'])) {
                $links = (array) $linkedAccount['eduPersonAffiliations'];
                $allAffiliations = array_merge($allAffiliations, $links);
            }
        }
    }
    $uniqueAffiliations = array_unique($allAffiliations);
    $expectedAffiliations = implode(';', $uniqueAffiliations);

    if (!$userPrincipalName || !$newMailAddress) {
        echo "Skipping user: Missing required 'uid' or 'email' fields." . PHP_EOL;
        echo str_repeat('-', 50) . PHP_EOL;
        continue;
    }

    echo "Processing UPN: {$userPrincipalName} (Source Anchor: {$source_anchor_uid})" . PHP_EOL;

    $existingUser = findEntraUserByUPN($graphServiceClient, $userPrincipalName, $customAffiliationAttribute);

    if ($existingUser) {
        $needsUpdate = false;

        $existingImmutableId = $existingUser->getOnPremisesImmutableId() ?? null;
        if ($existingImmutableId !== null && $existingImmutableId !== $source_anchor_uid) {
            echo "   [WARNING] Existing user's OnPremisesImmutableId ('{$existingImmutableId}') does not match source UID. **Immutable ID will not be updated.**" . PHP_EOL;
        }

        if ($existingUser->getDisplayName() !== $newDisplayName) {
            echo "   [CHANGE] Displayname needs update: '{$existingUser->getDisplayName()}' -> '{$newDisplayName}'" . PHP_EOL;
            $needsUpdate = true;
        }
        if (strtolower($existingUser->getMail() ?? '') !== strtolower($newMailAddress)) {
            echo "   [CHANGE] email needs update: '{$existingUser->getMail()}' -> '{$newMailAddress}'" . PHP_EOL;
            $needsUpdate = true;
        }
        if (($existingUser->getGivenName() ?? '') !== ($newGivenName ?? '')) {
            echo "   [CHANGE] Givenname needs update: '{$existingUser->getGivenName()}' -> '{$newGivenName}'" . PHP_EOL;
            $needsUpdate = true;
        }
        if (($existingUser->getSurname() ?? '') !== ($newFamilyName ?? '')) {
            echo "   [CHANGE] Surname needs update: '{$existingUser->getSurname()}' -> '{$newFamilyName}'" . PHP_EOL;
            $needsUpdate = true;
        }
        if (($existingUser->getCompanyName() ?? '') !== ($newCompanyName ?? '')) {
            echo "   [CHANGE] CompagnyName needs update: '{$existingUser->getCompanyName()}' -> '{$newCompanyName}'" . PHP_EOL;
            $needsUpdate = true;
        }
        $existingOtherMails = $existingUser->getOtherMails() ?? [];
        if (!in_array($newMailAddress, $existingOtherMails)) {
            echo "   [CHANGE] Email needs update." . PHP_EOL;
            $needsUpdate = true;
        }
        if (($existingUser->getUsageLocation() ?? '') !== "NL") { $needsUpdate = true; }
        if (($existingUser->getCountry() ?? '') !== "NL") { $needsUpdate = true; }

        $existingAffiliations = $existingUser->getAdditionalData()[$customAffiliationAttribute] ?? null;

        if (($existingAffiliations ?? '') !== ($expectedAffiliations ?? '')) {
            echo "   [CHANGE] Custom Affiliations ({$customAffiliationAttribute}) needs update: '{$existingAffiliations}' -> '{$expectedAffiliations}'" . PHP_EOL;
            $needsUpdate = true;
        }

        if ($needsUpdate) {
            $userUpdate = buildUserUpdateObject($userData, $userPrincipalName, $customAffiliationAttribute, null);
            updateEntraUser($graphServiceClient, $existingUser->getId(), $userUpdate);
        } else {
            echo "   [SKIP] User exists and no attribute changes detected." . PHP_EOL;
        }

    } else {
        echo "   [CREATE] User not found. Preparing to create new account..." . PHP_EOL;

        $randomPassword = generateRandomPassword(32);

        $newUser = buildUserUpdateObject($userData, $userPrincipalName, $customAffiliationAttribute, $source_anchor_uid, $randomPassword);

        try {
            $createdUser = $graphServiceClient->users()->post($newUser)->wait();
            echo "   Created user ID: " . $createdUser->getId() . " successfully. **OnPremisesImmutableId set.**" . PHP_EOL;
        } catch (\Exception $e) {
            echo "   Failed to create user {$userPrincipalName}: " . $e->getMessage() . PHP_EOL;
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