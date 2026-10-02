<?php
// Uruchom z linii polecen: php tools/generate_captcha_samples.php
// Generuje przykładowe obrazki do Bot_veryfication/, każdy nazwany hash1(tekst).png
// - dokładnie tak jak zakłada logika captchy w includes/functions.php.
// Wymaga rozszerzenia GD (na Arch Linuksie: pacman -S php-gd i włączenie extension=gd w php.ini).

// hash1() zdefiniowana wprost tutaj (kopia z common.php), żeby to narzędzie
// nie wymagało działającej bazy danych (common.php przy include otwiera połączenie).
function poly_hash($Text, $Base, $Mod) {
  $Hash = 0;
  for ($I = 0; $I < strlen($Text); $I++) $Hash = ($Hash * $Base + (ord($Text[$I]) + 1)) % $Mod;
  return $Hash;
}
function hash1($Text) {
  $A = poly_hash($Text, 131, 1000000007);
  $B = poly_hash($Text, 137, 998244353);
  return str_pad(dechex($A * 998244353 + $B), 16, '0', STR_PAD_LEFT);
}
function hash_captcha_text($Text) {
  return hash1(trim(mb_strtolower($Text)));
}

if (!extension_loaded('gd')) {
  die("Brak rozszerzenia GD. Zainstaluj: sudo pacman -S php-gd\n");
}

$OutDir = __DIR__ . '/../Bot_veryfication';
$Words = ['zamek', 'rower', 'kotlet', 'gwiazda', 'tunel', 'orkiestra', 'walizka', 'kubek', 'lustro', 'sosna'];

foreach ($Words as $Word) {
  $Hash = hash_captcha_text($Word);
  $Path = "$OutDir/$Hash.png";

  $Width = 200; $Height = 70;
  $Img = imagecreatetruecolor($Width, $Height);
  $Bg = imagecolorallocate($Img, 255, 255, 255);
  imagefill($Img, 0, 0, $Bg);

  // szum w tle - utrudnia OCR
  for ($I = 0; $I < 250; $I++) {
    $Color = imagecolorallocate($Img, rand(180, 220), rand(180, 220), rand(180, 220));
    imagesetpixel($Img, rand(0, $Width - 1), rand(0, $Height - 1), $Color);
  }

  $TextColor = imagecolorallocate($Img, 30, 30, 30);
  $FontSize = 5;
  $X = (int)(($Width - strlen($Word) * imagefontwidth($FontSize)) / 2);
  imagestring($Img, $FontSize, $X, 25, $Word, $TextColor);

  imagepng($Img, $Path);
  imagedestroy($Img);
  echo "Wygenerowano: $Word -> $Hash.png\n";
}

echo "Gotowe. Pamiętaj: jawny tekst ('zamek', 'rower'...) nie jest nigdzie zapisywany w bazie - tylko hash z nazwy pliku.\n";
