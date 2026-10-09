<?php
declare(strict_types=1);
require_once __DIR__.'/graphql_client.php';
function erpGraphQL(string $query, array $variables=[]): array {
    $result=(new ERPGraphQLClient())->query($query,$variables);
    if ($result['curl_error'] || $result['http_code'] !== 200 || !is_array($result['response'])) { throw new RuntimeException('ERP GraphQL request failed.'); }
    return $result['response'];
}
