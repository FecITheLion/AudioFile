<?php
/**
 * Generative Opus with Configurable Duration (PHP Edition)
 * Usage: 
 *   ?t=0      -> Streams infinitely forever
 *   ?t=10000  -> Generates a finite track lasting exactly 10,000 ms (10 seconds)
 */

mt_srand((int)(microtime(true) * 1000000));
set_time_limit(0);
ini_set('output_buffering', 'off');
while (ob_get_level()) {
    ob_end_clean();
}

$sampleRate = 44100;
$channels = 1;
$bitsPerSample = 16;
$blockAlign = ($channels * $bitsPerSample) / 8;
$byteRate = $sampleRate * $blockAlign;

// Read duration parameter 't' in milliseconds (default to 0 for infinite)
$durationMs = isset($_GET['t']) ? intval($_GET['t']) : 0;
$isInfinite = ($durationMs === 0);
$maxSamples = $isInfinite ? 0 : intval(($sampleRate * $durationMs) / 1000);

header('Content-Type: audio/wav');
header('Content-Disposition: inline; filename="configurable_generative_opus.wav"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');

function sendWavHeader($isInfinite, $maxSamples) {
    global $sampleRate, $byteRate, $blockAlign;
    
    if ($isInfinite) {
        $dataSize = 0xFFFFFFFF; 
        $fileSize = 0xFFFFFFFF; 
    } else {
        $dataSize = $maxSamples * $blockAlign;
        $fileSize = 36 + $dataSize;
    }

    $header  = "RIFF";
    $header .= pack('V', $fileSize);
    $header .= "WAVE";
    $header .= "fmt ";
    $header .= pack('V', 16);          
    $header .= pack('v', 1);           
    $header .= pack('v', 1);           
    $header .= pack('V', $sampleRate);
    $header .= pack('V', $byteRate);
    $header .= pack('v', $blockAlign);
    $header .= pack('v', 16);          
    $header .= "data";
    $header .= pack('V', $dataSize);
    
    echo $header;
    flush();
}

sendWavHeader($isInfinite, $maxSamples);

// --- INITIAL SEEDING ---
$currentBpm = floatval(mt_rand(88, 120));
$targetBpm = $currentBpm;
$baseHarmonics = [1, 2, 3, 4, 5, 6, 8];
$possibleRoots = [65.41, 73.42, 82.41, 98.00, 110.00, 123.47, 130.81, 146.83, 164.81];
shuffle($possibleRoots);

$currentRoot = $possibleRoots[0];
$targetRoot = $currentRoot;

$lineCount = 0;
$globalSampleIndex = 0;
$running = true;

// --- GENERATION LOOP ---
while ($running) {
    if ($lineCount % 3 === 0) {
        if (mt_rand(0, 1) === 0) {
            $targetBpm = (mt_rand(0, 1) === 0) ? mt_rand(75, 90) : mt_rand(125, 145);
        } else {
            $targetBpm = mt_rand(90, 120);
        }
        $targetRoot = $possibleRoots[mt_rand(0, count($possibleRoots) - 1)];
        if (mt_rand(0, 3) === 0) {
            $targetRoot *= (mt_rand(0, 1) === 0 ? 0.5 : 2.0);
        }
    } else {
        $targetBpm += mt_rand(-4, 4);
        $targetBpm = max(70, min(150, $targetBpm));
        $targetRoot = $possibleRoots[$lineCount % count($possibleRoots)];
    }

    $activeHarmonics = $baseHarmonics;
    if (mt_rand(0, 1) === 1) {
        shuffle($activeHarmonics);
    } else {
        $shift = mt_rand(0, count($activeHarmonics) - 1);
        $activeHarmonics = array_merge(array_slice($activeHarmonics, $shift), array_slice($activeHarmonics, 0, $shift));
    }

    $chunkBuffer = '';
    $syllableDuration = (60.0 / $currentBpm) * 0.5; 
    $samplesPerSyllable = intval($sampleRate * $syllableDuration);

    for ($syllable = 0; $syllable < 10 && $running; $syllable++) {
        $isStressed = ($syllable % 2 !== 0);
        
        $approachDetune = (mt_rand(-20, 20) / 100.0); 
        $releaseDrift   = (mt_rand(-25, 25) / 100.0); 
        $timbreShift    = mt_rand(75, 125) / 100.0;   

        for ($i = 0; $i < $samplesPerSyllable; $i++) {
            // Check if finite duration limit has been reached
            if (!$isInfinite && $globalSampleIndex >= $maxSamples) {
                $running = false;
                break;
            }

            $tLocal = $i / $sampleRate;
            $progress = $i / $samplesPerSyllable; 
            
            $currentRoot += ($targetRoot - $currentRoot) * 0.0002; 
            $currentBpm += ($targetBpm - $currentBpm) * 0.0001;     
            
            $harmonicSample = 0.0;
            $bassSample = 0.0;
            $padSample = 0.0;
            $drumSample = 0.0;

            if ($progress < 0.2) {
                $phaseMultiplier = 1.0 + ($approachDetune * (1.0 - ($progress / 0.2)));
                $envelope = ($progress / 0.2); 
            } elseif ($progress < 0.7) {
                $phaseMultiplier = 1.0;
                $dynamicWeight = $isStressed ? (0.5 + 0.5 * (($progress - 0.2) / 0.5)) : 0.75;
                $envelope = $dynamicWeight;
            } else {
                $phaseMultiplier = 1.0 + ($releaseDrift * (($progress - 0.7) / 0.3));
                $envelope = (1.0 - (($progress - 0.7) / 0.3)); 
            }

            $fadeIn = min(1.0, $tLocal / 0.03);
            $fadeOut = min(1.0, ($syllableDuration - $tLocal) / 0.03);
            $envelope *= ($fadeIn * $fadeOut);

            // Guqin Harmonic Strings
            foreach ($activeHarmonics as $hIndex => $n) {
                if ($isStressed && $hIndex > 4) continue; 
                $freq = $currentRoot * ($isStressed ? ($n * $timbreShift) : $n) * $phaseMultiplier;
                $amplitude = 1.0 / ($hIndex + 1);
                $harmonicSample += $amplitude * sin(2 * M_PI * $freq * $tLocal);
            }
            $harmonicSample = ($harmonicSample / count($activeHarmonics)) * $envelope * 0.5;

            // Sub-Bass Synth
            $subFreq = $currentRoot * 0.5;
            $bassSample = (sin(2 * M_PI * $subFreq * $tLocal) + (0.3 * sin(2 * M_PI * ($subFreq * 2.0) * $tLocal))) * 0.35;
            $bassSample *= (0.7 + 0.3 * $envelope);

            // Ambient Pad
            $padFreq = $currentRoot * 2.0;
            $padEnvelope = sin($progress * M_PI);
            $padSample = (sin(2 * M_PI * $padFreq * $tLocal) + 0.5 * sin(2 * M_PI * ($padFreq * 1.5) * $tLocal)) * 0.15 * $padEnvelope;

            // Drum Kit
            $hiHatNoise = (mt_rand(-1000, 1000) / 1000.0);
            $hiHatEnv = exp(-$tLocal * 50.0) * ($isStressed ? 0.3 : 0.12);
            $drumSample += $hiHatNoise * $hiHatEnv;

            if ($syllable === 1 || $syllable === 5 || $syllable === 9) {
                $kickPitch = max(38.0, 130.0 * exp(-$tLocal * 30.0));
                $kickEnv = exp(-$tLocal * 15.0);
                $drumSample += sin(2 * M_PI * $kickPitch * $tLocal) * $kickEnv * 0.5;
            }

            if ($syllable === 3 || $syllable === 7) {
                $snareNoise = (mt_rand(-1000, 1000) / 1000.0);
                $snareTone = sin(2 * M_PI * 180.0 * $tLocal);
                $snareEnv = exp(-$tLocal * 25.0);
                $drumSample += ($snareNoise * 0.5 + $snareTone * 0.2) * $snareEnv * 0.4;
            }

            if ($syllable === 8 && mt_rand(0, 1) === 1) {
                $rimNoise = (mt_rand(-1000, 1000) / 1000.0);
                $rimEnv = exp(-$tLocal * 80.0);
                $drumSample += $rimNoise * $rimEnv * 0.3;
            }

            $mixedValue = ($harmonicSample + $bassSample + $padSample + $drumSample) * 32767.0;
            $mixedValue = max(-32768.0, min(32767.0, $mixedValue));

            $chunkBuffer .= pack('s', intval($mixedValue));
            $globalSampleIndex++;
        }
    }

    if (!empty($chunkBuffer)) {
        echo $chunkBuffer;
        flush();
    }
    $lineCount++;
}
exit;
?>
