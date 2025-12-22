<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Ollama Configuration
define('OLLAMA_URL', 'http://localhost:11434/api/chat');
define('OLLAMA_MODEL', 'mistral');

// Tool definitions for the AI
$tools = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'search_thesportsdb',
            'description' => 'Search for football player information from TheSportsDB API. Returns player stats, team, nationality, position, height, weight, and biography. Use this for getting player statistics and basic information.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'player_name' => [
                        'type' => 'string',
                        'description' => 'The full name of the football player to search for (e.g., "Cristiano Ronaldo", "Lionel Messi")'
                    ]
                ],
                'required' => ['player_name']
            ]
        ]
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'search_wikipedia',
            'description' => 'Search for football player information from Wikipedia. Returns detailed biography, career information, and images. Use this for getting comprehensive career history and biographical details.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'player_name' => [
                        'type' => 'string',
                        'description' => 'The full name of the football player to search for (e.g., "Cristiano Ronaldo", "Lionel Messi")'
                    ]
                ],
                'required' => ['player_name']
            ]
        ]
    ]
];

/**
 * Call Ollama Mistral with tool support
 */
function callMistralAgent($messages, $tools) {
    $payload = [
        'model' => OLLAMA_MODEL,
        'messages' => $messages,
        'tools' => $tools,
        'stream' => false,
        'options' => [
            'temperature' => 0.7,
            'top_p' => 0.9,
            'num_predict' => 512  // Limit response length
        ]
    ];

    $ch = curl_init(OLLAMA_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180); // 3 minutes
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['error' => 'Ollama connection error: ' . $error];
    }
    
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => 'Ollama API error', 'code' => $httpCode, 'response' => $response];
    }

    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'JSON decode error: ' . json_last_error_msg()];
    }

    return $data;
}

/**
 * Search TheSportsDB for player information
 */
function searchTheSportsDB($playerName) {
    $url = 'https://www.thesportsdb.com/api/v1/json/3/searchplayers.php?p=' . urlencode($playerName);
    $response = @file_get_contents($url);
    
    if ($response === false) {
        return null;
    }
    
    $data = json_decode($response, true);
    return isset($data['player'][0]) ? $data['player'][0] : null;
}

/**
 * Search Wikipedia for player information
 */
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

/**
 * Execute a tool call
 */
function executeTool($toolName, $parameters) {
    switch ($toolName) {
        case 'search_thesportsdb':
            $result = searchTheSportsDB($parameters['player_name']);
            return $result ? $result : ['error' => 'Player not found in TheSportsDB'];
            
        case 'search_wikipedia':
            $result = searchWikipedia($parameters['player_name']);
            return $result ? $result : ['error' => 'Player not found in Wikipedia'];
            
        default:
            return ['error' => 'Unknown tool: ' . $toolName];
    }
}

/**
 * Process the agent conversation with tool calls
 */
function processAgentConversation($userMessage, $tools) {
    $messages = [
        [
            'role' => 'system',
            'content' => 'You are a football assistant with access to external tools.

Decide internally which tool is needed based on the users request:
- Use search_thesportsdb for stats and factual player data.
- Use search_wikipedia for biography and career narrative.

IMPORTANT RULES:
- Do NOT explain your tool choice.
- Do NOT mention tools, APIs, or sources in your response.
- Do NOT recommend what should be used.
- If information is needed, call the appropriate tool immediately.

Use one tool unless both are strictly necessary.
Use both only if required to answer the question fully.

After collecting the information, respond directly with the final answer in 3–4 sentences.'
        ],
        [
            'role' => 'user',
            'content' => $userMessage
        ]
    ];

    $maxIterations = 5;
    $iteration = 0;
    $toolResults = [];

    while ($iteration < $maxIterations) {
        $iteration++;
        
        // Call Mistral
        $response = callMistralAgent($messages, $tools);
        
        if (isset($response['error'])) {
            return [
                'success' => false,
                'message' => 'AI Error: ' . $response['error'],
                'data' => null
            ];
        }

        $assistantMessage = $response['message'] ?? null;
        
        if (!$assistantMessage) {
            return [
                'success' => false,
                'message' => 'უკაცრავად, AI-მ ვერ გასცა პასუხი.',
                'data' => null
            ];
        }

        // Add assistant message to history
        $messages[] = $assistantMessage;

        // Check if there are tool calls
        if (isset($assistantMessage['tool_calls']) && !empty($assistantMessage['tool_calls'])) {
            // Execute each tool call
            foreach ($assistantMessage['tool_calls'] as $toolCall) {
                $toolName = $toolCall['function']['name'];
                $toolArgs = json_decode($toolCall['function']['arguments'], true);
                
                // Execute the tool
                $toolResult = executeTool($toolName, $toolArgs);
                
                // Store results
                $toolResults[$toolName] = $toolResult;
                
                // Add tool result to messages
                $messages[] = [
                    'role' => 'tool',
                    'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE)
                ];
            }
            
            // Continue loop to get final response
            continue;
        }

        // No more tool calls, we have the final response
        $finalMessage = $assistantMessage['content'] ?? 'ინფორმაცია მიღებულია.';
        
        // Check if we got any player data
        $hasData = !empty($toolResults);
        
        return [
            'success' => $hasData,
            'message' => $finalMessage,
            'data' => $hasData ? [
                'sports' => $toolResults['search_thesportsdb'] ?? null,
                'wiki' => $toolResults['search_wikipedia'] ?? null
            ] : null
        ];
    }

    return [
        'success' => false,
        'message' => 'უკაცრავად, ძალიან ბევრი ნაბიჯი დასჭირდა. სცადე თავიდან.',
        'data' => null
    ];
}

// Main request handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $userMessage = $input['message'] ?? '';
    
    if (empty($userMessage)) {
        echo json_encode(['error' => 'No message provided']);
        exit;
    }
    
    // Process with AI agent
    $result = processAgentConversation($userMessage, $tools);
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}
?>