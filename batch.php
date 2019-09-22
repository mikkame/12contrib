<?php

$token = getenv('GH_TOKEN');

function call_github_api(string $url, string $token) {
    echo 'getting '.$url."\n";
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'User-Agent: 12contrib',
        'Authorization: bearer '.$token,
        'Content-Type: application/json',
    ]);

    $response = curl_exec($curl);
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $original_header = substr($response, 0, $header_size);
    $header = [];
    foreach (array_map(function ($row) {return explode(":", $row, 2);},explode("\n", $original_header)) as $row) {
        $header[$row[0]] = $row[1] ?? null;
    }
    $body = substr($response, $header_size);
    echo 'got '.$url."\n";
    return compact('header', 'body');
}

$language_cache = [];
$repo_cache = [];
$result = [];

// 一番使われているひらがなランキングより1〜4位が「いんかし」らしい、これらが含まれれいるものを簡易的に日本語とする。
foreach (preg_split("//u", 'いんかし', -1, PREG_SPLIT_NO_EMPTY) as $char) {

    $url  = 'https://api.github.com/search/issues?q='.urlencode($char).'+label:%22help%20wanted%22+state:open&sort=created&order=desc';

    while (true) {
        $response = call_github_api($url, $token);

        $json = json_decode($response['body'], true);
        foreach ($json['items'] as $item) {
            if (!isset( $result[$item['id']])) {
                if (!isset($language_cache[$item['repository_url']])) {
                    $language_cache[$item['repository_url']] = json_decode(call_github_api($item['repository_url'].'/languages', $token)['body'], true);
                }
                $item['languages'] = $language_cache[$item['repository_url']];

                if (!isset($repo_cache[$item['repository_url']])) {
                    $repo_cache[$item['repository_url']] = (json_decode(call_github_api($item['repository_url'], $token)['body'], true));
                }
                $item['stargazers_count'] = $repo_cache[$item['repository_url']]['stargazers_count'];
                $item['description'] = $repo_cache[$item['repository_url']]['description'];

                $result[$item['id']] = $item;
            }

        }

        if (isset($response['header']['Link'])) {
            $next = false;
            foreach (explode(",", $response['header']['Link']) as $link) {
                if (strpos($link, 'next') !== false) {
                    $url = substr($link, 2, -13);

                    $next = true;
                }
            }
            if (!$next) {
                break;
            }
        }
        unset($json);
        unset($response);
        seep(5);
    }
}

array_multisort( array_map( "strtotime", array_column( $result, "updated_at" ) ), SORT_DESC, $result);


$languages = [];
foreach ($result as &$item) {
    $languages = array_merge($languages, array_keys($item['languages']) ?? []);
    foreach (['user', 'url', 'repository_url', 'labels_url', 'comments_url', 'events_url', 'state', 'locked', 'body', 'node_id', 'assignee', 'assignees', 'milestone', 'created_at', 'closed_at', 'author_association'] as $key) {
        unset($item[$key]);
    }
}

$languages = array_count_values($languages);
arsort($languages);
$dist_languages =[];
foreach ($languages as $lang => $val) {
    $dist_languages[] = [
        'count' => $val,
        'filtered' => false,
        'icon' => str_replace('#', 'sharp', $lang),
        'name' => $lang,
    ];
}
file_put_contents('public/languages.json', json_encode($dist_languages));
file_put_contents('public/list.json', json_encode(array_values($result)));
