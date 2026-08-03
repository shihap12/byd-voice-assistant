<?php
$data = json_decode(file_get_contents('models.json'), true);
foreach ($data['models'] as $m) {
    if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [])) {
        echo $m['name'] . "\n";
    }
}
