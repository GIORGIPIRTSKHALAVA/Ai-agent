<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}


define('HF_API_KEY', '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');
define('HF_MODEL', 'meta-llama/Llama-3.1-8B-Instruct');
define('HF_API_URL', 'https://api-inference.huggingface.co/models/' . HF_MODEL);


$tools = [
    [
        'name' => 'search_thesportsdb',
        'description' => 'Search for football player information from TheSportsDB API. Returns player stats, team, nationality, position, height, weight, and biography.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'player_name' => [
                    'type' => 'string',
                    'description' => 'The name of the football player to search for'
                ]
            ],
            'required' => ['player_name']
        ]
    ],
    [
        'name' => 'search_wikipedia',
        'description' => 'Search for football player information from Wikipedia. Returns detailed biography, career information, and images.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'player_name' => [
                    'type' => 'string',
                    'description' => 'The name of the football player to search for'
                ]
            ],
            'required' => ['player_name']
        ]
    ]
];

function callHuggingFaceAPI($messages, $tools) {
    $systemPrompt = "You are a helpful football assistant. When a user asks about a football player, you should use the available tools to fetch information. First use search_thesportsdb, then search_wikipedia to get comprehensive information. Always respond in a friendly manner.

Available tools:
- search_thesportsdb: Gets player stats and basic info
- search_wikipedia: Gets detailed biography and career info

When calling tools, respond in this JSON format:
{\"tool\": \"tool_name\", \"parameters\": {\"player_name\": \"Player Name\"}}

After receiving tool results, provide a natural language response about the player.";

    $payload = [
        'inputs' => $systemPrompt . "\n\nConversation:\n" . json_encode($messages),
        'parameters' => [
            'max_new_tokens' => 500,
            'temperature' => 0.7,
            'top_p' => 0.95,
            'return_full_text' => false
        ]
    ];

    $ch = curl_init(HF_API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . HF_API_KEY,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => 'API request failed', 'code' => $httpCode];
    }

    return json_decode($response, true);
}

function searchTheSportsDB($playerName) {
    $url = 'https://www.thesportsdb.com/api/v1/json/3/searchplayers.php?p=' . urlencode($playerName);
    $response = @file_get_contents($url);
    
    if ($response === false) {
        return null;
    }
    
    $data = json_decode($response, true);
    return isset($data['player'][0]) ? $data['player'][0] : null;
}

function searchWikipedia($playerName) {
    // Search for the player
    $searchUrl = 'https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch=' . 
                 urlencode($playerName . ' footballer') . '&format=json&origin=*&srlimit=1';
    $searchResponse = @file_get_contents($searchUrl);
    
    if ($searchResponse === false) {
        return null;
    }
    
    $searchData = json_decode($searchResponse, true);
    
    if (empty($searchData['query']['search'])) {
        return null;
    }
    
    $pageTitle = $searchData['query']['search'][0]['title'];
    
    // Get page content
    $contentUrl = 'https://en.wikipedia.org/w/api.php?action=query&prop=extracts|pageimages&exintro=true&explaintext=true&titles=' . 
                  urlencode($pageTitle) . '&format=json&origin=*&piprop=original';
    $contentResponse = @file_get_contents($contentUrl);
    
    if ($contentResponse === false) {
        return null;
    }
    
    $contentData = json_decode($contentResponse, true);
    $pages = $contentData['query']['pages'];
    $page = reset($pages);
    
    return [
        'title' => $page['title'] ?? null,
        'extract' => $page['extract'] ?? null,
        'image' => $page['original']['source'] ?? null
    ];
}

function executeTool($toolName, $parameters) {
    switch ($toolName) {
        case 'search_thesportsdb':
            return searchTheSportsDB($parameters['player_name']);
        case 'search_wikipedia':
            return searchWikipedia($parameters['player_name']);
        default:
            return ['error' => 'Unknown tool'];
    }
}

function extractPlayerName($message) {
    $patterns = [
        '/about\s+([a-z\s]+?)(\?|$|stats|info)/i',
        '/who\s+is\s+([a-z\s]+?)(\?|$)/i',
        '/tell\s+me\s+about\s+([a-z\s]+?)(\?|$)/i',
        '/info\s+about\s+([a-z\s]+?)(\?|$)/i',
        '/([a-z\s]+?)\s+stats/i',
        '/([a-z\s]+?)\s+info/i',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $matches)) {
            return trim($matches[1]);
        }
    }
    
    $words = array_filter(explode(' ', $message), function($w) {
        return strlen($w) > 2;
    });
    
    if (count($words) > 0) {
        return implode(' ', array_slice($words, 0, 3));
    }
    
    return trim($message);
}

// Main request handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $userMessage = $input['message'] ?? '';
    
    if (empty($userMessage)) {
        echo json_encode(['error' => 'No message provided']);
        exit;
    }
    
    // Extract player name from message
    $playerName = extractPlayerName($userMessage);
    
    // Execute tools directly
    $sportsData = searchTheSportsDB($playerName);
    $wikiData = searchWikipedia($playerName);
    
    // Prepare response
    if (!$sportsData && !$wikiData) {
        $response = [
            'success' => false,
            'message' => "უკაცრავად, ინფორმაცია \"{$playerName}\"-ის შესახებ ვერ მოვიძიე. 😔 შეამოწმე სახელის სწორი წერა ან სცადე სხვა ფეხბურთელის სახელი.",
            'data' => null
        ];
    } else {
        $playerDisplayName = $sportsData['strPlayer'] ?? $wikiData['title'] ?? $playerName;
        $response = [
            'success' => true,
            'message' => "აი ინფორმაცია {$playerDisplayName}-ის შესახებ:",
            'data' => [
                'sports' => $sportsData,
                'wiki' => $wikiData
            ]
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
?>