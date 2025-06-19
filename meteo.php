<?php
$lat = $_GET['lat'] ?? null;
$lon = $_GET['lon'] ?? null;
$cleApi = "TA_CLE_API"; // Remplace par ta vraie clé OpenWeatherMap

if ($lat && $lon) {
    $url = "https://api.openweathermap.org/data/2.5/weather?lat=$lat&lon=$lon&units=metric&lang=fr&appid=$cleApi";
    $data = json_decode(file_get_contents($url), true);

    if (isset($data["main"])) {
        $temp = $data["main"]["temp"];
        $description = ucfirst($data["weather"][0]["description"]);
        $ville = $data["name"];
        echo "<strong>$ville</strong> : $temp°C – $description";
    } else {
        echo "Météo non disponible.";
    }
} else {
    echo "Coordonnées GPS manquantes.";
}
?>
