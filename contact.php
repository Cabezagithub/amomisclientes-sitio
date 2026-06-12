<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://amomisclientes.com');
header('Access-Control-Allow-Methods: POST');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// ── Config ────────────────────────────────────────────────
// Credenciales en config.php, UN NIVEL ARRIBA de public_html
// (fuera del directorio del deploy de Git, así no se borra en cada push)
require __DIR__ . '/../config.php';

$SMTP_HOST        = SMTP_HOST;
$SMTP_PORT        = SMTP_PORT;
$SMTP_USER        = SMTP_USER;
$SMTP_PASS        = SMTP_PASS;
$MAIL_TO          = MAIL_TO;
$RECAPTCHA_SECRET = RECAPTCHA_SECRET;
$RECAPTCHA_MIN_SCORE = 0.5;

// ── Input ─────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

$name       = trim(strip_tags($input['name']       ?? ''));
$email      = trim(strip_tags($input['email']      ?? ''));
$company    = trim(strip_tags($input['company']    ?? ''));
$phone      = trim(strip_tags($input['phone']      ?? ''));
$country    = trim(strip_tags($input['country']    ?? ''));
$rubro      = trim(strip_tags($input['rubro']      ?? ''));
$sucursales = trim(strip_tags($input['sucursales'] ?? ''));
$interest   = trim(strip_tags($input['interest']   ?? ''));
$message    = trim(strip_tags($input['message']    ?? ''));
$token      = trim($input['recaptcha_token']        ?? '');
$lang       = trim($input['lang']                  ?? 'es');
$honeypot   = trim($input['extra']                 ?? '');

// ── Honeypot: si el campo oculto viene con datos, es un bot ──
// Respondemos ok para no darle señales, pero descartamos todo.
if ($honeypot !== '') {
    error_log('AMC contact honeypot: descartado (' . substr($email, 0, 60) . ')');
    echo json_encode(['ok' => true]);
    exit;
}

// ── Validación ────────────────────────────────────────────
if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($message) < 10) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'validation']);
    exit;
}

// Verificación de dominio del email: tiene que existir y poder recibir correo
$emailDomain = substr(strrchr($email, '@'), 1);
if (!checkdnsrr($emailDomain, 'MX') && !checkdnsrr($emailDomain, 'A')) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'email_domain']);
    exit;
}

// ── reCAPTCHA v3 ──────────────────────────────────────────
// Solo verifica si hay secret configurado Y el token llegó.
// Si el token está vacío (reCAPTCHA no configurado en el front), deja pasar
// y loguea para que puedas detectar envíos sin verificar.
if (!empty($RECAPTCHA_SECRET) && !empty($token)) {
    $rc     = file_get_contents('https://www.google.com/recaptcha/api/siteverify?' . http_build_query(['secret' => $RECAPTCHA_SECRET, 'response' => $token]));
    $rcData = $rc ? json_decode($rc, true) : [];
    if (!($rcData['success'] ?? false) || ($rcData['score'] ?? 0) < $RECAPTCHA_MIN_SCORE) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'recaptcha_failed', 'score' => $rcData['score'] ?? 0]);
        exit;
    }
} elseif (!empty($RECAPTCHA_SECRET) && empty($token)) {
    // Secret configurado pero sin token: loguear y dejar pasar
    error_log('AMC contact warning: token reCAPTCHA vacío, se procesó igual');
}

// ── ID de referencia: aaaammddhhmmss + 4 dígitos random ──
$refId = date('YmdHis') . str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

// ── Datos técnicos del visitante ──────────────────────────
function visitor_ip() {
    // Hostinger puede pasar la IP real en X-Forwarded-For si hay proxy/CDN
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip  = trim($ips[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function parse_user_agent($ua) {
    $browser = 'Desconocido';
    if (stripos($ua, 'Edg/') !== false)          $browser = 'Edge';
    elseif (stripos($ua, 'OPR/') !== false)      $browser = 'Opera';
    elseif (stripos($ua, 'SamsungBrowser') !== false) $browser = 'Samsung Internet';
    elseif (stripos($ua, 'Chrome') !== false)    $browser = 'Chrome';
    elseif (stripos($ua, 'Safari') !== false)    $browser = 'Safari';
    elseif (stripos($ua, 'Firefox') !== false)   $browser = 'Firefox';

    $os = 'Desconocido';
    if (stripos($ua, 'Android') !== false)        $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os = 'iOS';
    elseif (stripos($ua, 'Windows') !== false)    $os = 'Windows';
    elseif (stripos($ua, 'Mac OS') !== false)     $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false)      $os = 'Linux';

    return "$browser / $os";
}

function geo_lookup($ip) {
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '';
    }
    $ch = curl_init("https://ipwho.is/{$ip}?fields=success,country,region,city,connection.isp");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    $raw = curl_exec($ch);
    curl_close($ch);
    if (!$raw) return '';
    $d = json_decode($raw, true);
    if (!($d['success'] ?? false)) return '';
    $parts = array_filter([$d['city'] ?? '', $d['region'] ?? '', $d['country'] ?? '']);
    $geo = implode(', ', $parts);
    if (!empty($d['connection']['isp'])) $geo .= ' — ISP: ' . $d['connection']['isp'];
    return $geo;
}

$visitorIp  = visitor_ip();
$visitorUa  = $_SERVER['HTTP_USER_AGENT'] ?? '';
$visitorGeo = geo_lookup($visitorIp);
$meta       = is_array($input['meta'] ?? null) ? $input['meta'] : [];
$m = function($k) use ($meta) { return trim(strip_tags((string)($meta[$k] ?? ''))); };

$techText = "IP: {$visitorIp}\n"
    . "Geo (por IP): {$visitorGeo}\n"
    . "Navegador: " . parse_user_agent($visitorUa) . "\n"
    . "User-Agent: {$visitorUa}\n"
    . "Idioma navegador: " . trim(strip_tags($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) . "\n"
    . "Zona horaria: " . $m('timezone') . "\n"
    . "Pantalla: " . $m('screen') . " (viewport " . $m('viewport') . ")\n"
    . "Plataforma: " . $m('platform') . " — Touch: " . $m('touch') . "\n"
    . "Referrer: " . $m('referrer');

// ── Mapeos de selects (valor del form → etiqueta legible) ─
$interestMap = [
    'demo'    => 'Quiere una demo',
    'precios' => 'Consulta precios y planes',
    'soporte' => 'Cliente: necesita soporte',
    'otro'    => 'Otro',
];
$interestLabel = $interestMap[$interest] ?? $interest;

$rubroMap = [
    'gastronomia' => 'Gastronomía / Cafetería',
    'estetica'    => 'Peluquería / Estética',
    'gimnasio'    => 'Gimnasio / Deporte',
    'retail'      => 'Comercio minorista',
    'servicios'   => 'Servicios',
    'otro'        => 'Otro',
];
$rubroLabel = $rubroMap[$rubro] ?? $rubro;

$sucursalesMap = [
    '1'   => '1 sucursal',
    '2-5' => '2 a 5 sucursales',
    '+5'  => 'Más de 5 sucursales',
];
$sucursalesLabel = $sucursalesMap[$sucursales] ?? $sucursales;

// ── Cuerpo del email interno ──────────────────────────────
$bodyText = "Nombre: $name\nEmail: $email\nNegocio/Empresa: $company\nMóvil/WhatsApp: $phone\nPaís: $country\nRubro: $rubroLabel\nSucursales: $sucursalesLabel\nInterés: $interestLabel\nIdioma: " . strtoupper($lang) . "\n\n$message\n\n── Datos técnicos ──\n$techText\n\nRef: $refId";

$bodyHtml = "<div style='font-family:Arial,sans-serif;max-width:600px;color:#0f172a'>"
  . "<div style='background:#0f172a;padding:24px 32px;border-radius:8px 8px 0 0'>"
  . "<span style='font-size:20px;font-weight:700;color:#fff'>Amo<span style='color:#FF6B35'>Mis</span>Clientes</span>"
  . "<span style='display:block;font-size:11px;letter-spacing:3px;color:rgba(255,255,255,.4);margin-top:4px'>NUEVO CONTACTO</span>"
  . "</div>"
  . "<div style='background:#f8fafc;padding:32px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px'>"
  . "<table style='width:100%;font-size:14px;border-collapse:collapse'>"
  . "<tr><td style='padding:8px 0;color:#64748b;width:140px'>Nombre</td><td style='padding:8px 0;font-weight:600'>" . htmlspecialchars($name) . "</td></tr>"
  . "<tr><td style='padding:8px 0;color:#64748b'>Email</td><td style='padding:8px 0'><a href='mailto:" . htmlspecialchars($email) . "' style='color:#FF6B35'>" . htmlspecialchars($email) . "</a></td></tr>"
  . ($company ? "<tr><td style='padding:8px 0;color:#64748b'>Negocio/Empresa</td><td style='padding:8px 0'>" . htmlspecialchars($company) . "</td></tr>" : "")
  . ($phone ? "<tr><td style='padding:8px 0;color:#64748b'>Móvil/WhatsApp</td><td style='padding:8px 0'><a href='https://wa.me/" . preg_replace('/\D/', '', $phone) . "' style='color:#FF6B35'>" . htmlspecialchars($phone) . "</a></td></tr>" : "")
  . ($country ? "<tr><td style='padding:8px 0;color:#64748b'>País</td><td style='padding:8px 0'>" . htmlspecialchars($country) . "</td></tr>" : "")
  . ($rubroLabel ? "<tr><td style='padding:8px 0;color:#64748b'>Rubro</td><td style='padding:8px 0'>" . htmlspecialchars($rubroLabel) . "</td></tr>" : "")
  . ($sucursalesLabel ? "<tr><td style='padding:8px 0;color:#64748b'>Sucursales</td><td style='padding:8px 0'>" . htmlspecialchars($sucursalesLabel) . "</td></tr>" : "")
  . ($interestLabel ? "<tr><td style='padding:8px 0;color:#64748b'>Interés</td><td style='padding:8px 0;font-weight:600'>" . htmlspecialchars($interestLabel) . "</td></tr>" : "")
  . "<tr><td style='padding:8px 0;color:#64748b'>Idioma</td><td style='padding:8px 0'>" . strtoupper(htmlspecialchars($lang)) . "</td></tr>"
  . "</table>"
  . "<div style='margin-top:20px;padding:20px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;font-size:14px;line-height:1.7;color:#475569'>"
  . nl2br(htmlspecialchars($message))
  . "</div>"
  . "<div style='margin-top:16px;padding:14px 20px;background:#f1f5f9;border-radius:6px;font-size:12px;line-height:1.8;color:#64748b'>"
  . "<strong style='color:#475569'>Datos técnicos</strong><br>"
  . nl2br(htmlspecialchars($techText))
  . "</div>"
  . "<p style='font-size:11px;color:#94a3b8;margin:16px 0 0'>Ref: {$refId}</p>"
  . "</div></div>";

// ── Auto-reply: textos por idioma ─────────────────────────
$autoReplyTexts = [
    'es' => [
        'subject'  => 'Gracias por contactarte con AmoMisClientes',
        'greeting' => 'Hola',
        'body'     => 'Recibimos tu consulta y te vamos a responder a la brevedad para coordinar los próximos pasos. Mientras tanto, te dejamos una copia de lo que nos enviaste:',
        'closing'  => 'Saludos cordiales,',
        'team'     => 'Equipo AmoMisClientes',
        'auto'     => 'Este es un mensaje automático. Si necesitás agregar información, simplemente respondé este correo.',
    ],
    'pt' => [
        'subject'  => 'Obrigado por entrar em contato com AmoMisClientes',
        'greeting' => 'Olá',
        'body'     => 'Recebemos sua mensagem e responderemos em breve para combinar os próximos passos. Enquanto isso, segue uma cópia do que você nos enviou:',
        'closing'  => 'Atenciosamente,',
        'team'     => 'Equipe AmoMisClientes',
        'auto'     => 'Esta é uma mensagem automática. Se precisar adicionar informações, basta responder a este e-mail.',
    ],
    'en' => [
        'subject'  => 'Thank you for contacting AmoMisClientes',
        'greeting' => 'Hello',
        'body'     => 'We have received your message and will get back to you shortly to coordinate the next steps. In the meantime, here is a copy of what you sent us:',
        'closing'  => 'Best regards,',
        'team'     => 'AmoMisClientes Team',
        'auto'     => 'This is an automated message. If you need to add any information, simply reply to this email.',
    ],
];
$ar = $autoReplyTexts[$lang] ?? $autoReplyTexts['es'];

$arBodyText = "{$ar['greeting']} {$name},\n\n{$ar['body']}\n\n---\n{$message}\n---\n\n{$ar['closing']}\n{$ar['team']}\n\n{$ar['auto']}";

$arBodyHtml = "<div style='font-family:Arial,sans-serif;max-width:600px;color:#0f172a'>"
  . "<div style='background:#0f172a;padding:24px 32px;border-radius:8px 8px 0 0'>"
  . "<span style='font-size:20px;font-weight:700;color:#fff'>Amo<span style='color:#FF6B35'>Mis</span>Clientes</span>"
  . "</div>"
  . "<div style='background:#f8fafc;padding:32px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px'>"
  . "<p style='font-size:15px;margin:0 0 16px'>" . htmlspecialchars($ar['greeting']) . " <strong>" . htmlspecialchars($name) . "</strong>,</p>"
  . "<p style='font-size:14px;line-height:1.7;color:#475569;margin:0 0 20px'>" . htmlspecialchars($ar['body']) . "</p>"
  . "<div style='padding:20px;background:#fff;border:1px solid #e2e8f0;border-left:3px solid #FF6B35;border-radius:6px;font-size:14px;line-height:1.7;color:#475569'>"
  . nl2br(htmlspecialchars($message))
  . "</div>"
  . "<p style='font-size:14px;margin:24px 0 4px'>" . htmlspecialchars($ar['closing']) . "</p>"
  . "<p style='font-size:14px;font-weight:600;margin:0'>" . htmlspecialchars($ar['team']) . "</p>"
  . "<p style='font-size:11px;color:#94a3b8;margin:24px 0 0;border-top:1px solid #e2e8f0;padding-top:16px'>" . htmlspecialchars($ar['auto']) . "</p>"
  . "</div></div>";

// ── vtiger CRM: alta de Lead vía webservice API ───────────
function vtiger_create_lead($crmUrl, $crmUser, $crmAccessKey, $refId, $name, $email, $company, $phone, $country, $rubroLabel, $sucursalesLabel, $interestLabel, $message, $lang, $techText) {
    $ws = rtrim($crmUrl, '/') . '/webservice.php';

    $http = function($method, $params) use ($ws) {
        $ch = curl_init();
        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_URL, $ws . '?' . http_build_query($params));
        } else {
            curl_setopt($ch, CURLOPT_URL, $ws);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return ['success' => false, 'error' => ['message' => "curl: $err"]];
        $data = json_decode($raw, true);
        return $data ?: ['success' => false, 'error' => ['message' => "respuesta no JSON: " . substr($raw, 0, 200)]];
    };

    // 1. Challenge
    $r = $http('GET', ['operation' => 'getchallenge', 'username' => $crmUser]);
    if (!($r['success'] ?? false)) return ['ok' => false, 'err' => 'challenge: ' . ($r['error']['message'] ?? '?')];
    $token = $r['result']['token'];

    // 2. Login
    $r = $http('POST', [
        'operation' => 'login',
        'username'  => $crmUser,
        'accessKey' => md5($token . $crmAccessKey),
    ]);
    if (!($r['success'] ?? false)) return ['ok' => false, 'err' => 'login: ' . ($r['error']['message'] ?? '?')];
    $session = $r['result']['sessionName'];
    $userId  = $r['result']['userId'];

    // 3. Crear Lead
    $parts     = preg_split('/\s+/', trim($name), 2);
    $firstname = $parts[0] ?? '';
    $lastname  = $parts[1] ?? $parts[0]; // vtiger exige lastname

    $lead = [
        'firstname'        => $firstname,
        'lastname'         => $lastname,
        'company'          => $company !== '' ? $company : 'Sin especificar',
        'email'            => $email,
        'mobile'           => $phone,
        'country'          => $country,
        'industry'         => $rubroLabel,
        'leadsource'       => 'sitio web AmoMisClientes',
        'description'      => "Ref: {$refId}\n"
            . "── Formulario web amomisclientes.com ──\n"
            . "Fecha: " . date('Y-m-d H:i:s') . "\n"
            . "Nombre: {$name}\n"
            . "Email: {$email}\n"
            . "Negocio/Empresa: {$company}\n"
            . "Móvil/WhatsApp: {$phone}\n"
            . "País: {$country}\n"
            . "Rubro: {$rubroLabel}\n"
            . "Sucursales: {$sucursalesLabel}\n"
            . "Interés: {$interestLabel}\n"
            . "Idioma: " . strtoupper($lang) . "\n"
            . "\nMensaje:\n{$message}"
            . "\n\n── Datos técnicos ──\n{$techText}",
        'assigned_user_id' => $userId,
    ];

    $r = $http('POST', [
        'operation'   => 'create',
        'sessionName' => $session,
        'elementType' => 'Leads',
        'element'     => json_encode($lead),
    ]);

    // 4. Logout (best-effort)
    $http('POST', ['operation' => 'logout', 'sessionName' => $session]);

    return ($r['success'] ?? false)
        ? ['ok' => true, 'id' => $r['result']['id'] ?? '']
        : ['ok' => false, 'err' => 'create: ' . ($r['error']['message'] ?? '?')];
}

// ── Envío SMTP SSL directo (sin dependencias) ─────────────
function smtp_send($host, $port, $user, $pass, $from, $fromName, $to, $replyTo, $replyName, $subject, $htmlBody, $textBody, $ics = null) {
    $boundary = md5(uniqid());

    $ctx  = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $sock = stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) return ['ok' => false, 'err' => "socket: $errstr ($errno)"];

    $read = function() use ($sock) {
        $r = '';
        while ($line = fgets($sock, 515)) {
            $r .= $line;
            if ($line[3] === ' ') break;
        }
        return $r;
    };
    $cmd = function($line) use ($sock, $read) {
        fwrite($sock, $line . "\r\n");
        return $read();
    };

    $read(); // banner
    $r = $cmd("EHLO amomisclientes.com");
    if (strpos($r, '250') === false) return ['ok' => false, 'err' => "EHLO: $r"];

    $r = $cmd("AUTH LOGIN");
    $cmd(base64_encode($user));
    $r = $cmd(base64_encode($pass));
    if (strpos($r, '235') === false) return ['ok' => false, 'err' => "AUTH: $r"];

    $r = $cmd("MAIL FROM:<{$user}>");
    if (strpos($r, '250') === false) return ['ok' => false, 'err' => "MAIL FROM: $r"];

    $r = $cmd("RCPT TO:<{$to}>");
    if (strpos($r, '250') === false) return ['ok' => false, 'err' => "RCPT TO: $r"];

    $cmd("DATA");

    $subjectEncoded = "=?UTF-8?B?" . base64_encode($subject) . "?=";
    $headers  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
    $headers .= "To: {$to}\r\n";
    $headers .= "Reply-To: =?UTF-8?B?" . base64_encode($replyName) . "?= <{$replyTo}>\r\n";
    $headers .= "Subject: {$subjectEncoded}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($textBody)) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    if ($ics !== null) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/calendar; charset=UTF-8; method=REQUEST\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($ics)) . "\r\n";
    }
    $body .= "--{$boundary}--";

    fwrite($sock, $headers . "\r\n" . $body . "\r\n.\r\n");
    $r = $read();
    $cmd("QUIT");
    fclose($sock);

    return strpos($r, '250') !== false
        ? ['ok' => true]
        : ['ok' => false, 'err' => "DATA end: $r"];
}

// ── Notificación a Slack ──────────────────────────────────
function slack_notify($webhookUrl, $refId, $name, $email, $company, $phone, $country, $rubroLabel, $sucursalesLabel, $interestLabel, $lang, $message) {
    $excerpt = mb_strlen($message) > 300 ? mb_substr($message, 0, 300) . '…' : $message;
    $lines = [
        ":incoming_envelope: *Nuevo contacto en amomisclientes.com*",
        "*Nombre:* {$name}" . ($company ? " ({$company})" : ''),
        "*Email:* {$email}",
    ];
    if ($phone)           $lines[] = "*Móvil/WhatsApp:* {$phone}";
    if ($country)         $lines[] = "*País:* {$country}";
    if ($rubroLabel)      $lines[] = "*Rubro:* {$rubroLabel}";
    if ($sucursalesLabel) $lines[] = "*Sucursales:* {$sucursalesLabel}";
    if ($interestLabel)   $lines[] = "*Interés:* {$interestLabel}";
    $lines[] = "*Idioma:* " . strtoupper($lang);
    $lines[] = ">>> {$excerpt}";
    $lines[] = "_Ref: {$refId}_";

    $payload = json_encode(['text' => implode("\n", $lines)], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    return ($raw === 'ok')
        ? ['ok' => true]
        : ['ok' => false, 'err' => $err ?: "respuesta: " . substr((string)$raw, 0, 100)];
}

// ── Invitación de calendario (.ics) ───────────────────────
function ics_escape($text) {
    return str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $text);
}

function ics_fold($line) {
    // RFC 5545: líneas de máx. 75 octetos, continuación con CRLF + espacio
    $out = '';
    while (strlen($line) > 73) {
        $out .= substr($line, 0, 73) . "\r\n ";
        $line = substr($line, 73);
    }
    return $out . $line;
}

function build_ics($refId, $organizerEmail, $attendeeEmail, $summary, $description, $startDate) {
    // Evento de día completo que abarca 3 días desde el día del contacto.
    // DTEND es exclusivo en iCalendar: start + 3 días marca exactamente 3 días en el calendario.
    $dtStart = $startDate->format('Ymd');
    $dtEnd   = (clone $startDate)->modify('+3 days')->format('Ymd');
    $dtStamp = gmdate('Ymd\THis\Z');

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//AmoMisClientes//Contacto Web//ES',
        'METHOD:REQUEST',
        'BEGIN:VEVENT',
        ics_fold('UID:' . $refId . '@amomisclientes.com'),
        'DTSTAMP:' . $dtStamp,
        'DTSTART;VALUE=DATE:' . $dtStart,
        'DTEND;VALUE=DATE:' . $dtEnd,
        ics_fold('SUMMARY:' . ics_escape($summary)),
        ics_fold('DESCRIPTION:' . ics_escape($description)),
        ics_fold('ORGANIZER;CN=AMC Web:mailto:' . $organizerEmail),
        ics_fold('ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=FALSE:mailto:' . $attendeeEmail),
        'STATUS:CONFIRMED',
        'TRANSP:TRANSPARENT',
        'END:VEVENT',
        'END:VCALENDAR',
    ];
    return implode("\r\n", $lines) . "\r\n";
}

$subject = "Nuevo contacto AmoMisClientes — {$name}" . ($company ? " ({$company})" : '');

$result = smtp_send(
    $SMTP_HOST, $SMTP_PORT,
    $SMTP_USER, $SMTP_PASS,
    $SMTP_USER, 'AMC Web',
    $MAIL_TO,
    $email, $name,
    $subject,
    $bodyHtml, $bodyText
);

if ($result['ok']) {
    // Notificación a Slack (best-effort: si falla, no afecta la respuesta)
    if (defined('SLACK_WEBHOOK_URL') && SLACK_WEBHOOK_URL !== '') {
        $slackResult = slack_notify(
            SLACK_WEBHOOK_URL,
            $refId, $name, $email, $company, $phone, $country, $rubroLabel, $sucursalesLabel, $interestLabel, $lang, $message
        );
        if (!$slackResult['ok']) {
            error_log('AMC slack error: ' . ($slackResult['err'] ?? ''));
        }
    }

    // Alta de Lead en vtiger CRM (best-effort: si falla, no afecta la respuesta)
    if (defined('VTIGER_URL') && VTIGER_URL !== '') {
        $crmResult = vtiger_create_lead(
            VTIGER_URL, VTIGER_USER, VTIGER_ACCESS_KEY,
            $refId, $name, $email, $company, $phone, $country,
            $rubroLabel, $sucursalesLabel, $interestLabel, $message, $lang, $techText
        );
        if (!$crmResult['ok']) {
            // Log de fallback: registro completo en JSON Lines para reprocesar después.
            // Vive junto a config.php, fuera de public_html (no lo borra el deploy
            // ni es accesible desde la web).
            $fallbackEntry = json_encode([
                'ref'        => $refId,
                'fecha'      => date('Y-m-d H:i:s'),
                'error'      => $crmResult['err'] ?? '?',
                'name'       => $name,
                'email'      => $email,
                'company'    => $company,
                'phone'      => $phone,
                'country'    => $country,
                'rubro'      => $rubroLabel,
                'sucursales' => $sucursalesLabel,
                'interest'   => $interestLabel,
                'lang'       => $lang,
                'message'    => $message,
                'tech'       => $techText,
            ], JSON_UNESCAPED_UNICODE);
            file_put_contents(__DIR__ . '/../crm-fallback.log', $fallbackEntry . "\n", FILE_APPEND | LOCK_EX);
            error_log('AMC vtiger error: ' . ($crmResult['err'] ?? ''));
        }
    }

    // Invitación de calendario a Gmail (best-effort: si falla, no afecta la respuesta)
    if (defined('CALENDAR_INVITE_TO') && CALENDAR_INVITE_TO !== '') {
        $startDate = new DateTime('today');
        $icsDescription = "Ref: {$refId}\n"
            . "Nombre: {$name}\n"
            . "Email: {$email}\n"
            . "Negocio/Empresa: {$company}\n"
            . "Móvil/WhatsApp: {$phone}\n"
            . "País: {$country}\n"
            . "Rubro: {$rubroLabel}\n"
            . "Sucursales: {$sucursalesLabel}\n"
            . "Interés: {$interestLabel}\n"
            . "Idioma: " . strtoupper($lang) . "\n\n"
            . $message;
        $ics = build_ics($refId, $SMTP_USER, CALENDAR_INVITE_TO, 'amomisclientes contacto', $icsDescription, $startDate);

        $icsResult = smtp_send(
            $SMTP_HOST, $SMTP_PORT,
            $SMTP_USER, $SMTP_PASS,
            $SMTP_USER, 'AMC Web',
            CALENDAR_INVITE_TO,
            $SMTP_USER, 'AMC Web',
            'amomisclientes contacto',
            nl2br(htmlspecialchars($icsDescription)),
            $icsDescription,
            $ics
        );
        if (!$icsResult['ok']) {
            error_log('AMC calendar invite error: ' . ($icsResult['err'] ?? ''));
        }
    }

    // Auto-reply al visitante (best-effort: si falla, no afecta la respuesta)
    $arResult = smtp_send(
        $SMTP_HOST, $SMTP_PORT,
        $SMTP_USER, $SMTP_PASS,
        $SMTP_USER, 'AmoMisClientes',
        $email,
        $MAIL_TO, 'AmoMisClientes',
        $ar['subject'],
        $arBodyHtml, $arBodyText
    );
    if (!$arResult['ok']) {
        error_log('AMC auto-reply error: ' . ($arResult['err'] ?? ''));
    }

    echo json_encode(['ok' => true]);
} else {
    error_log('AMC contact error: ' . ($result['err'] ?? ''));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'mail_failed', 'detail' => $result['err'] ?? '']);
}
