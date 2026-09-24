<?php

/**
 * @file
 * Mide tres investigaciones a la vez, en producción y con dinero de verdad.
 *
 * Existe por una pregunta de José Raúl del 22-09-2026: si entran varios
 * alumnos a la vez, ¿de verdad se atienden en paralelo o hacen cola? El
 * 22-09 se pasó de un recogedor a tres —el cron del minuto más dos líneas con
 * `flock` a los :20 y a los :40—, y de eso solo estaba comprobado que los tres
 * arrancan y no se pisan (`bin/simulacro-cola.php`, motor simulado, 200 turnos
 * sin un solo duplicado). Lo que NO estaba comprobado es lo único que se le
 * promete al cliente: tres investigaciones DE VERDAD, a la vez, en el
 * servidor de verdad.
 *
 * Esa diferencia no es un detalle. El simulacro responde en milésimas, así que
 * no dice nada del consumo de memoria, ni de la CPU, ni de lo que tarda el
 * proveedor cuando tres turnos le hablan a la vez. Este guion sí, porque
 * ocurre de verdad.
 *
 * ────────────────────────────────────────────────────────────────────────
 * GASTA DINERO DE VERDAD.
 * ────────────────────────────────────────────────────────────────────────
 *
 * Cada turno es una llamada al proveedor —con unos 107 000 tokens de prompt en
 * el agente de prospección— más las búsquedas que decida hacer. Medido el
 * 22-09-2026: entre 0,216 y 0,334 USD por turno de prospección. Tres turnos
 * mínimos salen por 0,35-0,70 USD. Las búsquedas de Tavily van aparte y
 * dependen del plan contratado: **desde aquí no se pueden medir**.
 *
 * Por eso `lanzar` exige escribir `SI-GASTA` a mano. Con el motor simulado
 * activo no lo pide: entonces no se paga nada y sirve para ensayar el propio
 * guion, que es como se verificó antes de subirlo.
 *
 * ¿Por qué no se crean usuarios de WordPress? Porque el camino del alumno
 * —botón en LearnDash, SSO, panel— ya está comprobado y no es lo que se mide
 * aquí. Se usan las cuentas de Drupal que ya existen. **Consecuencia honesta:
 * este ensayo NO prueba la puerta de entrada, solo lo que pasa detrás.**
 *
 * Uso:
 * @code
 *   # 1. Ver si las cuentas pueden investigar esta semana.
 *   #    NO GASTA Y NO ESCRIBE: solo lee.
 *   drush php:script bin/ensayo-concurrencia.php -- cupo
 *
 *   # 2. Lanzar los tres turnos y quedarse mirando (hasta 8 minutos).
 *   drush php:script bin/ensayo-concurrencia.php -- lanzar SI-GASTA
 *
 *   # 3. Las cifras finales, con los ids que imprimió el paso 2.
 *   drush php:script bin/ensayo-concurrencia.php -- informe 131,132,133
 * @endcode
 *
 * Opciones, en cualquier orden y con la forma `clave=valor`:
 *
 * - `cuentas=` nombres separados por comas. Por defecto, tres cuentas hechas
 *   para esto, que hay que crear una vez (el guion dice cómo si faltan).
 * - `agente=` por defecto `prospecting_diagnostic`, el único que investiga.
 * - `mensaje=` el primer mensaje del alumno. El de fábrica trae territorio y
 *   cliente ideal para que el agente pueda salir a buscar en el primer turno.
 * - `vigilar=` segundos de observación tras encolar. Por defecto 480.
 * - `forzar=si` para lanzar aunque la cola no esté vacía. Por defecto NO se
 *   lanza: con trabajo ajeno en la cola, las esperas medidas no son las de
 *   este ensayo.
 *
 * QUÉ MIDE, y de dónde sale cada número:
 *
 * - **Espera de cada turno**: del instante en que este guion lo encola al
 *   instante en que un recogedor lo reserva. Se mide sondeando la tabla de
 *   colas cada segundo, NO con las marcas de tiempo de `sld_ai_usage`: esas
 *   son el `getRequestTime` del proceso de drush, común a todo lo que procese
 *   esa pasada, y ya hicieron parecer instantáneo un turno de 45 segundos.
 * - **Paralelismo**: el solapamiento real de los tres intervalos
 *   [reservado, terminado]. Si el solapamiento de los tres es cero, es que se
 *   atendieron en serie, por mucho que haya tres recogedores.
 * - **Duplicados**: cada conversación tiene que acabar con UNA respuesta del
 *   agente. Dos significan dos llamadas pagadas y dos respuestas seguidas en
 *   la pantalla del alumno.
 * - **Coste real**: lo que quedó anotado en `sld_ai_usage` para esas sesiones.
 * - **Pico de memoria**: lo máximo que se vio, mientras duraba, en los procesos
 *   de drush DE ESTA CUENTA del servidor —el cron y los recogedores—, leído con
 *   `ps` y con el comando al lado para poder auditarlo. Es una observación
 *   externa y aproximada; si el alojamiento no deja ejecutar `ps`, lo dice en
 *   lugar de inventarlo. Acotarlo a la cuenta propia no es un detalle: en un
 *   alojamiento compartido, `ps -eo` trae los procesos de todas, y el de otra
 *   cuenta se colaba con nuestra etiqueta.
 *
 * QUÉ NO HACE:
 *
 * - **No borra nada.** Las conversaciones que crea son reales, de cuentas
 *   reales, y se ven en el panel del alumno y en Consumo. Borrar sus apuntes
 *   de consumo haría que los topes de gasto olvidaran dinero que sí se pagó.
 *   Para dejar el sitio limpio antes de una demo está `drush sld:limpiar-
 *   pruebas`, que ya se usó el 22-09 con respaldo previo.
 * - **No gasta el cupo de nadie por su cuenta**: si la misión de la semana
 *   está disponible, este ensayo la abre, igual que la abriría el alumno.
 *   `cupo` lo dice antes de que se decida.
 */

declare(strict_types=1);

use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Site\Settings;
use Drupal\sales_leadership_diagnostic\DTO\Entitlement;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\MessageRole;
use Drupal\sales_leadership_diagnostic\MissionState;
use Drupal\sales_leadership_diagnostic\Plugin\QueueWorker\DiagnosticTurnWorker;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use Drupal\sales_leadership_diagnostic\Service\Conversation\ConversationService;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticPromptManager;
use Drupal\sales_leadership_diagnostic\Service\Engine\DiagnosticEngineFactory;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBoxFactory;
use Drupal\sales_leadership_diagnostic\Service\Research\ResearchEntitlementService;
use Drupal\sales_leadership_diagnostic\Service\Search\SearchProviderInterface;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;
use Drupal\user\UserInterface;

/**
 * Los valores de fábrica, y por qué son esos.
 *
 * Van juntos en una función y no en constantes sueltas para que quien cambie
 * uno lea al lado la razón del que tiene al lado.
 *
 * @param string $clave
 *   Cuál: `cuentas`, `agente`, `vigilar` o `mensaje`.
 *
 * @return string|int
 *   El valor por defecto.
 */
function ensayo_por_defecto(string $clave): string|int {
  return match ($clave) {
    // Tres cuentas hechas para este ensayo, y no las de los alumnos de prueba.
    // La razón es que el turno abre la MISIÓN DE LA SEMANA de quien lo manda:
    // medir con la cuenta de alguien le gasta su investigación, y si esa cuenta
    // es la de una demo, la demo llega con la semana ya gastada. Tampoco se
    // crean usuarios de WordPress: el camino del alumno —botón, SSO, panel— ya
    // está comprobado y no es lo que se mide aquí.
    //
    // A cambio, estas cuentas no arrastran memoria del alumno ni historial de
    // cuentas, así que su contexto es algo más corto que el de un alumno con
    // recorrido. La diferencia es pequeña —el prompt lo dominan los documentos
    // del agente, que son los mismos— pero conviene saberlo al leer el coste.
    'cuentas' => 'ensayo.cola1,ensayo.cola2,ensayo.cola3',

    // El único agente que sale a investigar, y por tanto el único cuyos turnos
    // pasan por la cola.
    'agente' => 'prospecting_diagnostic',

    // Ocho minutos de vigilancia: el turno más largo medido en producción fue
    // de 201 segundos, y el último de los tres puede esperar a que le toque su
    // recogedor.
    'vigilar' => 480,

    // El primer mensaje del alumno, con territorio y cliente ideal A
    // PROPÓSITO. El agente pregunta antes de investigar, así que un «hola»
    // daría un turno corto y barato que no mediría nada. Aun así, decidir si
    // busca es cosa del modelo: el informe dice cuántas búsquedas hizo cada
    // uno para que no haya que suponerlo.
    'mensaje' => 'Haz el trabajo por mí esta semana. Vendo software de gestión de flotas para empresas de transporte y logística en México; mi territorio es Monterrey, Guadalajara y Ciudad de México. Mi cliente ideal son operadores con entre 50 y 300 unidades propias. Dame el pack de cuentas de esta semana con lo que encuentres.',

    default => '',
  };
}

/**
 * Separa la acción, lo posicional y las opciones `clave=valor`.
 *
 * `SI-GASTA` se saca aparte y no cuenta como argumento posicional: si contara,
 * escribirlo antes o después cambiaría de sitio los demás, y eso convierte una
 * autorización en una trampa.
 *
 * @param array $extra
 *   Lo que llegó tras el `--` de drush.
 *
 * @return array{0: string, 1: array<int, string>, 2: array<string, string>, 3: bool}
 *   Acción, argumentos sueltos, opciones y si se autorizó el gasto.
 */
function ensayo_argumentos(array $extra): array {
  $sueltos = [];
  $opciones = [];
  $gasta = FALSE;

  foreach ($extra as $pieza) {
    $pieza = (string) $pieza;

    if ($pieza === 'SI-GASTA') {
      $gasta = TRUE;

      continue;
    }

    if (str_contains($pieza, '=')) {
      [$clave, $valor] = explode('=', $pieza, 2);
      $opciones[trim($clave)] = $valor;

      continue;
    }

    $sueltos[] = $pieza;
  }

  $accion = array_shift($sueltos) ?? 'ayuda';

  return [$accion, $sueltos, $opciones, $gasta];
}

/**
 * Busca las cuentas por su nombre.
 *
 * @param string $lista
 *   Nombres separados por comas.
 *
 * @return array{cuentas: array<string, \Drupal\user\UserInterface>, faltan: array<int, string>}
 *   Las que existen, por nombre, y las que no.
 */
function ensayo_cuentas(string $lista): array {
  $almacen = \Drupal::entityTypeManager()->getStorage('user');
  $cuentas = [];
  $faltan = [];

  foreach (array_filter(array_map('trim', explode(',', $lista))) as $nombre) {
    $encontradas = $almacen->loadByProperties(['name' => $nombre]);

    if ($encontradas === []) {
      $faltan[] = $nombre;

      continue;
    }

    $cuentas[$nombre] = reset($encontradas);
  }

  return ['cuentas' => $cuentas, 'faltan' => $faltan];
}

/**
 * Si la búsqueda externa está encendida en todo el sitio.
 *
 * Es la primera de las tres puertas de `ToolBoxFactory`: el interruptor del
 * módulo y que haya llave de buscador. Se mira aparte porque el arreglo es
 * distinto del de las otras dos, y porque así el aviso no culpa a una cuenta
 * de algo que es de los ajustes.
 */
function ensayo_buscador_encendido(): bool {
  return (bool) \Drupal::config('sales_leadership_diagnostic.settings')->get('search.enabled')
    && \Drupal::service(SearchProviderInterface::class)->isAvailable();
}

/**
 * Qué puede hacer una cuenta esta semana, y si su turno se encolaría.
 *
 * **No escribe nada**, y eso costó una corrección: la primera versión llamaba a
 * `forUser()` y a `mayResearch()`, y los dos CREAN la fila de la semana en
 * `sld_research_entitlement` si aún no existe. No gasta dinero ni cupo —es la
 * misma fila que nacería al entrar el alumno—, pero es una escritura en
 * producción, y esto se había anunciado como una lectura. Lo vio Jarvis leyendo
 * el guion antes de ejecutarlo, el 23-09-2026.
 *
 * Así que la fila se lee a mano y la decisión se reconstruye con el DTO de
 * verdad, no con reglas copiadas: si mañana cambian las condiciones para
 * investigar, cambian en un solo sitio.
 *
 * @param \Drupal\user\UserInterface $cuenta
 *   Alumno.
 * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface $agente
 *   Agente con el que se va a ensayar.
 * @param bool $buscador
 *   Si la búsqueda está encendida en el sitio.
 *
 * @return array<string, string|int|bool|float|null>
 *   Una fila lista para imprimir.
 */
function ensayo_estado_de_cuenta(UserInterface $cuenta, DiagnosticAgentInterface $agente, bool $buscador): array {
  $uid = (int) $cuenta->id();
  $entitlements = \Drupal::service(ResearchEntitlementService::class);
  $gasto = \Drupal::service(SpendGuard::class);

  // `periodFor()` sí es lectura: calcula la semana ISO en la zona de la
  // persona y no toca la base.
  $periodo = $entitlements->periodFor($uid);
  $maxRechecks = $entitlements->maxRechecks();

  $fila = \Drupal::database()->select('sld_research_entitlement', 'e')
    ->fields('e', ['mission_state', 'rechecks_used'])
    ->condition('uid', $uid)
    ->condition('period', $periodo)
    ->execute()
    ->fetchAssoc();

  // Sin fila, la semana está entera: es exactamente lo que crearía `forUser()`.
  $entitlement = new Entitlement(
    uid: $uid,
    period: $periodo,
    state: MissionState::tryFrom((string) ($fila['mission_state'] ?? '')) ?? MissionState::Available,
    rechecksUsed: (int) ($fila['rechecks_used'] ?? 0),
  );

  $acceso = $entitlement->access($maxRechecks);
  $suyo = $gasto->statusForUser($uid);

  // Una conversación suya todavía «procesando» significa que hay un turno
  // pendiente de antes. Lanzar encima mezclaría ese trabajo con el del ensayo
  // y las esperas medidas no serían las de nadie.
  $pendientes = (int) \Drupal::database()->select('sld_diagnostic_session', 's')
    ->condition('uid', $uid)
    ->condition('status', DiagnosticStatus::Processing->value)
    ->countQuery()
    ->execute()
    ->fetchField();

  return [
    'nombre' => $cuenta->getAccountName(),
    'uid' => $uid,
    'zona' => $cuenta->getTimeZone() ?: '(la del sitio)',
    'periodo' => $periodo,
    'mision' => $entitlement->state->value,
    'sinFila' => $fila === FALSE,
    'rechecks' => $entitlement->rechecksUsed . '/' . $maxRechecks,
    'acceso' => $acceso->value,
    // Las tres puertas, en el mismo orden que en producción.
    'encolaria' => $buscador && $agente->canSearch() && $acceso->allowsAnything(),
    'gastado' => $suyo === NULL ? NULL : (float) $suyo['spent'],
    'tope' => $suyo === NULL ? NULL : (float) $suyo['limit'],
    'pendientes' => $pendientes,
  ];
}

/**
 * Lo que hay ahora mismo en la cola de turnos.
 *
 * Devuelve una fila por elemento con la sesión a la que pertenece y si está
 * reservado. No usa el servicio de tiempo de Drupal: en un proceso que vigila
 * durante minutos, `getRequestTime()` se quedó congelado en el arranque y
 * haría parecer reservado lo que ya caducó.
 *
 * @return array<int, array{item: int, sesion: int, reservado: bool, creado: int}>
 *   Elementos de la cola de turnos.
 */
function ensayo_cola(): array {
  $bd = \Drupal::database();

  if (!$bd->schema()->tableExists(DatabaseQueue::TABLE_NAME)) {
    return [];
  }

  $ahora = time();
  $filas = [];

  $resultado = $bd->select(DatabaseQueue::TABLE_NAME, 'q')
    ->fields('q', ['item_id', 'data', 'expire', 'created'])
    ->condition('name', DiagnosticTurnWorker::QUEUE)
    ->execute();

  foreach ($resultado as $fila) {
    $datos = @unserialize((string) $fila->data, ['allowed_classes' => FALSE]);

    $filas[] = [
      'item' => (int) $fila->item_id,
      'sesion' => (int) ($datos['session_id'] ?? 0),
      'reservado' => (int) $fila->expire > $ahora,
      'creado' => (int) $fila->created,
    ];
  }

  return $filas;
}

/**
 * Estado y respuestas de unas conversaciones, leídos de la base.
 *
 * Se consulta la base y no el almacén de entidades porque este proceso vive
 * varios minutos y la caché estática le devolvería la sesión tal como estaba
 * al empezar.
 *
 * @param array<int, int> $ids
 *   Conversaciones.
 *
 * @return array<int, array{estado: string, respuestas: int}>
 *   Estado y número de respuestas del agente, por sesión.
 */
function ensayo_sesiones(array $ids): array {
  $bd = \Drupal::database();

  $estados = $bd->select('sld_diagnostic_session', 's')
    ->fields('s', ['id', 'status'])
    ->condition('id', $ids, 'IN')
    ->execute()
    ->fetchAllKeyed();

  $respuestas = $bd->select(DiagnosticMessageRepository::TABLE, 'm')
    ->fields('m', ['session_id'])
    ->condition('session_id', $ids, 'IN')
    ->condition('role', MessageRole::Assistant->value)
    ->execute()
    ->fetchCol();

  $porSesion = array_count_values(array_map('intval', $respuestas));
  $salida = [];

  foreach ($ids as $id) {
    $salida[$id] = [
      'estado' => (string) ($estados[$id] ?? '?'),
      'respuestas' => (int) ($porSesion[$id] ?? 0),
    ];
  }

  return $salida;
}

/**
 * Lo que ocupan, juntos, los procesos propios de drush, y cuál es el mayor.
 *
 * Da la SUMA y no solo el mayor porque la pregunta de capacidad es cuánto pesan
 * varias investigaciones a la vez: dos recogedores de 70 MB son 140, y eso es
 * lo que hay que comparar con lo que el servidor tiene libre. Informar solo el
 * mayor contestaba a otra pregunta.
 *
 * Es una observación DESDE FUERA, con `ps`, y por eso puede no estar
 * disponible: en algunos alojamientos `shell_exec` está desactivado o `ps` solo
 * ve los procesos propios. Cuando no se puede medir devuelve null, que el
 * informe traduce por «no medido». Un cero sería mentira.
 *
 * Mira SOLO los procesos de esta cuenta del servidor y solo los de drush, y eso
 * costó una corrección. La primera versión usaba `ps -eo`, que en un
 * alojamiento compartido ve los procesos de TODAS las cuentas, y filtraba por
 * la palabra «php»: el cron de otra cuenta ocupaba 73 MB y se llevaba el
 * máximo, justo al lado de los 72,7 MB que habíamos medido para un turno. Una
 * cifra ajena, plausible y con nuestra etiqueta. Lo vio Jarvis leyendo el
 * guion, el 23-09-2026.
 *
 * @return array{mayor: float, que: string, suma: float, cuantos: int}|null
 *   Lo ocupado y por quién, o null si no se pudo medir.
 */
function ensayo_pico_de_memoria(): ?array {
  // Dos detalles, y sin los dos esta función devuelve null creyendo que no hay
  // nada que medir:
  //
  // - `-ww`: drush exporta COLUMNS=80 y `ps` obedece esa variable, así que
  //   dentro de drush cada línea se corta a los 80 caracteres. En el servidor
  //   la palabra «drush» aparece hacia el carácter 115 de la línea del cron, o
  //   sea justo detrás del corte: el filtro no encontraba nada y el informe
  //   decía «no medido» con toda la razón aparente. Lo encontró Jarvis
  //   midiéndolo: 80 caracteres dentro de drush, 1018 con `-ww`. El recorte
  //   cortaba además el nombre de este guion, así que la exclusión de más abajo
  //   fallaba y el vigilante se medía A SÍ MISMO.
  // - el uid lo resuelve el propio intérprete de órdenes, porque `getmyuid()`
  //   de PHP devuelve el dueño del ARCHIVO y no el del proceso, y aquí hacían
  //   falta dos cosas distintas que se parecen mucho.
  $salida = @shell_exec('ps -ww -o rss=,args= -u "$(id -u)" 2>/dev/null');

  if (!is_string($salida) || trim($salida) === '') {
    return NULL;
  }

  $mayor = 0;
  $suma = 0;
  $cuantos = 0;
  $quien = '';

  foreach (explode("\n", $salida) as $linea) {
    if (!preg_match('/^\s*(\d+)\s+(.*)$/', $linea, $partes)) {
      continue;
    }

    // Los que generan turnos son el cron y los recogedores, todos por drush.
    // Este mismo guion queda fuera: el vigilante no es lo que se mide.
    if (!str_contains($partes[2], 'drush') || str_contains($partes[2], 'ensayo-concurrencia')) {
      continue;
    }

    // Y tiene que ser PHP DE VERDAD, no algo que lleve «drush» escrito. Los
    // envoltorios —el `flock … sh -c` de cada recogedor, el bucle del cron—
    // mencionan drush en su línea de órdenes y ocupan tres megas. Si el
    // muestreo pilla el envoltorio y no a su hijo, el pico saldría en 3 MB con
    // la etiqueta de «pico de memoria»: una cifra tranquilizadora y falsa. Se
    // mira el ejecutable, que es lo único que distingue al proceso que trabaja.
    $ejecutable = basename(strtok($partes[2], ' ') ?: '');

    if (preg_match('/^php[0-9.]*$/', $ejecutable) !== 1) {
      continue;
    }

    $cuantos++;
    $suma += (int) $partes[1];

    if ((int) $partes[1] > $mayor) {
      $mayor = (int) $partes[1];
      $quien = trim($partes[2]);
    }
  }

  if ($cuantos === 0) {
    return NULL;
  }

  return [
    'mayor' => round($mayor / 1024, 1),
    'que' => ensayo_comando_corto($quien),
    'suma' => round($suma / 1024, 1),
    'cuantos' => $cuantos,
  ];
}

/**
 * Deja de un comando lo único que distingue quién trabajó.
 *
 * Recortar la línea por el principio no sirve: lo que ocupa son la ruta
 * absoluta del binario y las dos opciones `-d` del cron, y lo que distingue al
 * cron de un recogedor está AL FINAL. Cortando a noventa caracteres, el informe
 * decía `/opt/cpanel/ea-php84/root/usr/bin/php -d session.gc_divisor=100 -d
 * error_log=/home/labai/l` y no se sabía cuál de los cuatro procesos era. Lo
 * vio Jarvis el 23-09-2026, comparando la cifra con su propio muestreo.
 *
 * Así que se quitan las opciones y las rutas, y queda `php drush.php cron` o
 * `php drush.php queue:run sld_diagnostic_turn`, que es la respuesta.
 */
function ensayo_comando_corto(string $comando): string {
  $piezas = preg_split('/\s+/', trim($comando)) ?: [];
  $limpio = [];
  $saltar = FALSE;

  foreach ($piezas as $pieza) {
    if ($saltar) {
      $saltar = FALSE;

      continue;
    }

    // `-d clave=valor` viene en dos piezas; `-dclave=valor`, en una.
    if ($pieza === '-d') {
      $saltar = TRUE;

      continue;
    }

    if (str_starts_with($pieza, '-d')) {
      continue;
    }

    $limpio[] = str_contains($pieza, '/') ? basename($pieza) : $pieza;
  }

  return mb_substr(implode(' ', $limpio), 0, 120);
}

/**
 * Comprueba que se puede lanzar sin gastar de más, y dice por qué no.
 *
 * Cuanto puede impedir un ensayo limpio se mira ANTES de mandar el primer
 * mensaje. Es la diferencia entre no gastar nada y gastar tres llamadas para
 * descubrir que la medición no valía.
 *
 * @param array<string, array<string, mixed>> $estados
 *   Lo que devolvió ensayo_estado_de_cuenta() por cada cuenta.
 * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface $agente
 *   Agente con el que se va a ensayar.
 * @param bool $buscador
 *   Si la búsqueda está encendida en el sitio.
 * @param bool $forzar
 *   Cierto para lanzar aunque la cola traiga trabajo ajeno.
 *
 * @return array<int, string>
 *   Los motivos para no lanzar. Vacío si se puede.
 */
function ensayo_reparos(array $estados, DiagnosticAgentInterface $agente, bool $buscador, bool $forzar): array {
  $reparos = [];

  // Un turno solo se encola si PUEDE investigar, y eso depende de tres cosas
  // distintas. Separarlas importa porque el arreglo de cada una es distinto:
  // el agente se configura, la búsqueda global se enciende y el cupo semanal
  // se espera. Cuando todas se resumían en «la cuenta no puede investigar», el
  // aviso culpaba a la cuenta de algo que era de los ajustes.
  if (!$buscador) {
    $reparos[] = 'La búsqueda externa está apagada en los ajustes del módulo o falta la llave del buscador: ningún turno pasaría por la cola.';
  }

  if (!$agente->canSearch()) {
    $reparos[] = sprintf(
      'El agente «%s» no tiene la búsqueda concedida, así que NINGUNO de sus turnos pasa por la cola. Este ensayo solo tiene sentido con el agente que investiga.',
      $agente->label(),
    );
  }

  foreach ($estados as $estado) {
    // Esto es lo más importante de todo el guion. Si el turno no se encola,
    // `submitMessage()` lo ejecuta AQUÍ MISMO, en este proceso: se paga igual
    // y no se mide nada, porque no pasa por la cola.
    if ($estado['encolaria'] !== TRUE && $estado['acceso'] === 'NOT_AVAILABLE') {
      $reparos[] = sprintf(
        '%s ya gastó su misión y sus comprobaciones de %s: su turno NO se encolaría, se ejecutaría aquí mismo y se pagaría sin medir nada.',
        $estado['nombre'],
        $estado['periodo'],
      );
    }

    if ($estado['pendientes'] > 0) {
      $reparos[] = sprintf(
        '%s tiene %d conversación(es) en «procesando»: hay trabajo suyo de antes sin terminar.',
        $estado['nombre'],
        $estado['pendientes'],
      );
    }

    if ($estado['tope'] !== NULL && $estado['gastado'] >= $estado['tope']) {
      $reparos[] = sprintf(
        '%s llegó a su tope de gasto (%.2f de %.2f USD).',
        $estado['nombre'],
        $estado['gastado'],
        $estado['tope'],
      );
    }
  }

  try {
    \Drupal::service(SpendGuard::class)->assertGlobalHeadroom();
  }
  catch (\Throwable $error) {
    $reparos[] = 'Tope global: ' . $error->getMessage();
  }

  $enCola = count(ensayo_cola());

  if ($enCola > 0 && !$forzar) {
    $reparos[] = sprintf(
      'La cola trae ya %d turno(s). Las esperas medidas serían las de la fila, no las del ensayo. Con forzar=si se lanza igual.',
      $enCola,
    );
  }

  return $reparos;
}

/**
 * Crea una conversación para una cuenta, como la crearía el alumno.
 *
 * @param \Drupal\user\UserInterface $cuenta
 *   Alumno.
 * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface $agente
 *   Agente con el que conversa.
 *
 * @return \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface
 *   La conversación, ya guardada y lista para recibir el mensaje.
 */
function ensayo_crear_sesion(UserInterface $cuenta, DiagnosticAgentInterface $agente): DiagnosticSessionInterface {
  // El prompt se compone igual que en producción, con sus documentos, y se
  // copia a la sesión: es lo que permite saber después con qué se conversó.
  $prompt = \Drupal::service(DiagnosticPromptManager::class)->composeFor($agente);
  $cursos = $agente->getCourseIds();

  $sesion = \Drupal::entityTypeManager()->getStorage('sld_diagnostic_session')->create([
    'uid' => (int) $cuenta->id(),
    'wp_user_id' => '',
    'course_id' => (string) (reset($cursos) ?: ''),
    'agent' => $agente->id(),
    'diagnostic_version' => $agente->getVersion(),
    'prompt_snapshot' => $prompt,
    'prompt_hash' => hash('sha256', $prompt),
    'started_at' => \Drupal::time()->getRequestTime(),
  ]);
  $sesion->setStatus(DiagnosticStatus::InProgress);
  $sesion->save();

  return $sesion;
}

/**
 * Se queda mirando la cola y las conversaciones, y cuenta lo que pasa.
 *
 * Sondea cada segundo porque es la resolución que hace falta para distinguir
 * «los tres a la vez» de «uno detrás de otro»: los recogedores entran a los
 * :00, :20 y :40 del minuto.
 *
 * @param array<int, array{sesion: int, cuenta: string, encolado: float}> $turnos
 *   Lo lanzado, con el instante en que se encoló cada uno.
 * @param int $segundos
 *   Cuánto mirar como mucho.
 *
 * @return array<int, array{reservado: float|null, terminado: float|null, memoria: array{mayor: float, que: string, suma: float, cuantos: int}|null}>
 *   Hitos de cada sesión y el instante de más memoria observado.
 */
function ensayo_vigilar(array $turnos, int $segundos): array {
  $ids = array_column($turnos, 'sesion');
  $porSesion = array_column($turnos, 'cuenta', 'sesion');
  $base = array_column($turnos, 'antes', 'sesion');
  $arranque = microtime(TRUE);

  $hitos = [];

  foreach ($ids as $id) {
    $hitos[$id] = ['reservado' => NULL, 'terminado' => NULL];
  }

  $pico = NULL;
  $visto = [];

  printf("\nVigilando hasta %d s. Cada línea es un cambio de verdad, no un latido.\n\n", $segundos);

  while (microtime(TRUE) - $arranque < $segundos) {
    $ahora = microtime(TRUE);
    $transcurrido = $ahora - $arranque;

    $enCola = [];

    foreach (ensayo_cola() as $elemento) {
      $enCola[$elemento['sesion']] = $elemento['reservado'];
    }

    $estados = ensayo_sesiones($ids);
    $memoria = ensayo_pico_de_memoria();

    // Se guarda el instante de más carga SUMADA, no el del proceso más gordo:
    // lo que estrecha el servidor es el total de lo que corre a la vez.
    if ($memoria !== NULL && $memoria['suma'] > ($pico['suma'] ?? 0)) {
      $pico = $memoria;
    }

    $terminados = 0;

    foreach ($ids as $id) {
      $etiqueta = $porSesion[$id] . ' (sesión ' . $id . ')';
      $reservado = $enCola[$id] ?? NULL;

      if ($reservado === TRUE && $hitos[$id]['reservado'] === NULL) {
        $hitos[$id]['reservado'] = $ahora;
        printf("  %5.1fs  %s: lo tomó un recogedor\n", $transcurrido, $etiqueta);
      }

      // Fuera de la cola y ya no procesando: terminó. Se piden las dos cosas
      // porque el elemento desaparece al borrarse y el estado al guardarse, y
      // entre lo uno y lo otro hay un instante en que parecería terminado sin
      // haber escrito nada.
      $fuera = !array_key_exists($id, $enCola);
      $quieto = $estados[$id]['estado'] !== DiagnosticStatus::Processing->value;

      if ($fuera && $quieto && $hitos[$id]['terminado'] === NULL) {
        $hitos[$id]['terminado'] = $ahora;
        printf(
          "  %5.1fs  %s: terminó en «%s» con %d respuesta(s) nueva(s)\n",
          $transcurrido,
          $etiqueta,
          $estados[$id]['estado'],
          $estados[$id]['respuestas'] - ($base[$id] ?? 0),
        );
      }

      // Volver a estar libre después de haber sido tomado es un reintento: el
      // recogedor murió o el trabajador lanzó una excepción. Interesa verlo en
      // el momento, porque explica una espera larga que si no parece un
      // misterio.
      $clave = $id . ':suelto';

      if ($reservado === FALSE && $hitos[$id]['reservado'] !== NULL && $hitos[$id]['terminado'] === NULL && !isset($visto[$clave])) {
        $visto[$clave] = TRUE;
        printf("  %5.1fs  %s: AVISO, volvió a la cola sin terminar (reintento)\n", $transcurrido, $etiqueta);
      }

      $terminados += $hitos[$id]['terminado'] === NULL ? 0 : 1;
    }

    if ($terminados === count($ids)) {
      printf(
        $terminados === 1 ? "\n  Terminó a los %.1f s.\n" : "\n  Los %d terminaron a los %.1f s.\n",
        ...($terminados === 1 ? [microtime(TRUE) - $arranque] : [$terminados, microtime(TRUE) - $arranque]),
      );

      break;
    }

    // Un latido cada medio minuto. Un turno que investiga puede tardar tres
    // minutos, y una pantalla muda tanto tiempo se lee como colgada: quien
    // vigila acaba cortando el ensayo justo antes de que termine.
    $medioMinuto = (int) ($transcurrido / 30);

    if ($medioMinuto > 0 && !isset($visto['latido:' . $medioMinuto])) {
      $visto['latido:' . $medioMinuto] = TRUE;
      $reservados = count(array_filter($enCola));

      printf(
        "  %5.1fs  (sigo aquí: %d en curso, %d esperando, %d terminados%s)\n",
        $transcurrido,
        $reservados,
        count($enCola) - $reservados,
        $terminados,
        $pico === NULL ? '' : sprintf(', %.0f MB en %d proceso(s)', $pico['suma'], $pico['cuantos']),
      );
    }

    sleep(1);
  }

  foreach ($hitos as $id => $hito) {
    $hitos[$id]['memoria'] = $pico;
  }

  return $hitos;
}

/**
 * Los ids de una lista separada por comas.
 *
 * @return array<int, int>
 *   Los que eran números, sin ceros.
 */
function ensayo_ids(string $lista): array {
  return array_values(array_filter(array_map('intval', explode(',', $lista))));
}

/**
 * Las conversaciones dadas, con el nombre de su dueño.
 *
 * Es lo que esperan el vigilante y el informe. El instante de encolado se pone
 * aquí, al llamar, porque quien llama lo hace justo después de mandar.
 *
 * @param array<int, int> $ids
 *   Conversaciones.
 * @param bool $conBase
 *   Falso para no tomar la foto de respuestas previas. Se usa al releer un
 *   ensayo ya corrido: allí la foto de AHORA no dice nada del turno que
 *   interesa, y ponerla haría que el informe afirmara lo que no sabe.
 *
 * @return array<int, array{sesion: int, cuenta: string, encolado: float, antes?: int}>
 *   Una fila por conversación.
 */
function ensayo_turnos_de_sesiones(array $ids, bool $conBase = TRUE): array {
  $duenos = \Drupal::database()->select('sld_diagnostic_session', 's')
    ->fields('s', ['id', 'uid'])
    ->condition('id', $ids, 'IN')
    ->execute()
    ->fetchAllKeyed();

  $nombres = [];
  $cuentas = \Drupal::entityTypeManager()->getStorage('user')
    ->loadMultiple(array_unique(array_map('intval', $duenos)));

  foreach ($cuentas as $cuenta) {
    $nombres[(int) $cuenta->id()] = $cuenta->getAccountName();
  }

  $turnos = [];
  $ahora = microtime(TRUE);

  foreach ($ids as $id) {
    $fila = [
      'sesion' => $id,
      'cuenta' => $nombres[(int) ($duenos[$id] ?? 0)] ?? ('sesión ' . $id),
      'encolado' => $ahora,
    ];

    if ($conBase) {
      // Lo que ya había antes de este turno, para poder restarlo.
      $fila['antes'] = ensayo_respuestas_de($id);
      $fila['metricas'] = ensayo_metricas_de($id);
    }

    $turnos[] = $fila;
  }

  return $turnos;
}

/**
 * Cuántas respuestas del agente tiene ya una conversación.
 *
 * Se toma ANTES de mandar el mensaje, y de ahí sale el único número que
 * demuestra que no hubo duplicado: las respuestas NUEVAS. Contar el total solo
 * vale si la conversación acaba de nacer, y desde que se puede medir sobre
 * conversaciones ya empezadas —que es lo que hace falta para que el agente
 * investigue— el total daba «duplicado» en cuanto había un turno anterior.
 */
function ensayo_respuestas_de(int $id): int {
  return (int) \Drupal::database()->select(DiagnosticMessageRepository::TABLE, 'm')
    ->condition('session_id', $id)
    ->condition('role', MessageRole::Assistant->value)
    ->countQuery()
    ->execute()
    ->fetchField();
}

/**
 * Lo consumido por una conversación hasta este instante.
 *
 * Se toma ANTES de mandar el mensaje y se resta después, porque estas cifras
 * son ACUMULADAS por conversación. Sin restar, el informe llamaba «coste del
 * ensayo» a la suma del ensayo más toda su preparación: el 23-09-2026 dijo
 * 0,3399 USD cuando los turnos medidos habían costado 0,08 y el resto era de
 * los turnos de calentamiento. Lo vio Jarvis sumando a mano.
 *
 * @return array{llamadas: int, usd: float, entrada: int, cache: int, salida: int, busquedas: int, caracteres: int}
 *   Los contadores de esa conversación.
 */
function ensayo_metricas_de(int $id): array {
  $bd = \Drupal::database();

  $u = $bd->query(
    'SELECT COUNT(*) n, COALESCE(SUM(cost_usd), 0) usd, COALESCE(SUM(input_tokens), 0) it, COALESCE(SUM(cached_input_tokens), 0) ct, COALESCE(SUM(output_tokens), 0) ot FROM {sld_ai_usage} WHERE session_id = :s',
    [':s' => $id],
  )->fetchObject();

  // Solo `buscar_web`: sin el filtro, las anotaciones del ledger cuentan como
  // búsquedas y el número sale inflado. Ya pasó una vez, y era un número que
  // acabó en un documento para el cliente.
  $h = $bd->query(
    'SELECT COUNT(*) n, COALESCE(SUM(retrieved_chars), 0) chars FROM {sld_tool_call} WHERE session_id = :s AND allowed = 1 AND tool = :t',
    [':s' => $id, ':t' => 'buscar_web'],
  )->fetchObject();

  return [
    'llamadas' => (int) $u->n,
    'usd' => (float) $u->usd,
    'entrada' => (int) $u->it,
    'cache' => (int) $u->ct,
    'salida' => (int) $u->ot,
    'busquedas' => (int) $h->n,
    'caracteres' => (int) $h->chars,
  ];
}

/**
 * Lo último que dijo el agente en una conversación.
 *
 * `conversar` lo imprime porque es lo que decide la respuesta siguiente: sin
 * verlo, avanzar la conversación es adivinar.
 */
function ensayo_ultima_respuesta(int $id): string {
  $mensajes = \Drupal::service(ConversationService::class)->getConversation($id);
  $ultimo = end($mensajes);

  return $ultimo === FALSE ? '(sin respuesta)' : $ultimo->content;
}

/**
 * Imprime lo que cada cuenta puede hacer esta semana. No gasta nada.
 *
 * @param array<string, array<string, mixed>> $estados
 *   Filas de ensayo_estado_de_cuenta().
 */
function ensayo_imprimir_cupo(array $estados): void {
  printf(
    "\n%-18s %5s %-9s %-12s %-9s %-16s %s\n",
    'CUENTA',
    'UID',
    'SEMANA',
    'MISIÓN',
    'RECHECKS',
    'GASTO DEL MES',
    '¿SU TURNO SE ENCOLA?',
  );

  foreach ($estados as $estado) {
    printf(
      "%-18s %5d %-9s %-12s %-9s %-16s %s\n",
      $estado['nombre'],
      $estado['uid'],
      $estado['periodo'],
      $estado['mision'],
      $estado['rechecks'],
      $estado['tope'] === NULL
        ? 'sin tope'
        : sprintf('%.2f / %.2f', $estado['gastado'], $estado['tope']),
      $estado['encolaria'] ? 'sí' : 'NO — se ejecutaría al vuelo',
    );

    if ($estado['sinFila'] === TRUE) {
      printf("%18s  (aún no tiene fila de esta semana; nace sola cuando entra)\n", '');
    }

    if ($estado['pendientes'] > 0) {
      printf("%18s OJO: %d conversación(es) suyas siguen «procesando».\n", '', $estado['pendientes']);
    }
  }

  $global = \Drupal::service(SpendGuard::class)->statusGlobal();

  if ($global !== NULL) {
    printf(
      "\nGasto global del mes: %.2f de %.2f USD (%d %%).\n",
      $global['spent'],
      $global['limit'],
      $global['percent'],
    );
  }
}

/**
 * Las cifras finales de un ensayo ya corrido.
 *
 * @param array<int, int> $ids
 *   Conversaciones del ensayo.
 * @param array<int, array{reservado: float|null, terminado: float|null, memoria: array{mayor: float, que: string, suma: float, cuantos: int}|null}> $hitos
 *   Lo que vio el vigilante, si lo hubo.
 * @param array<int, array{sesion: int, cuenta: string, encolado: float, antes?: int, metricas?: array<string, float|int>}> $turnos
 *   Lo lanzado, si se lanzó en esta misma ejecución. Cuando trae la foto de
 *   antes, las cifras que se informan son las DEL TURNO; sin ella, solo se
 *   puede informar el total de la conversación, y se dice.
 */
function ensayo_informe(array $ids, array $hitos = [], array $turnos = []): void {
  $estados = ensayo_sesiones($ids);
  $porSesion = array_column($turnos, 'cuenta', 'sesion');
  $encolados = array_column($turnos, 'encolado', 'sesion');
  // Sin la foto de antes no se puede saber qué respuesta es de este turno, y
  // entonces lo que se informa es el total de la conversación. Se dice, en vez
  // de llamar «respuestas del turno» a otra cosa: en una conversación con
  // historia, el total daría «duplicado» sin que hubiera ninguno.
  $base2 = array_column($turnos, 'antes', 'sesion');
  $hayBase = $turnos !== [] && array_key_exists('antes', reset($turnos));

  $costeDelDisparo = 0.0;
  $costeAcumulado = 0.0;
  $duplicadas = [];
  $sinRespuesta = [];
  $base = array_column($turnos, 'metricas', 'sesion');

  print "\n═══ TURNO A TURNO ═══\n\n";

  foreach ($ids as $id) {
    $ahora = ensayo_metricas_de($id);
    $antes = $base[$id] ?? NULL;

    // Lo del turno medido es la diferencia. Estas cifras son acumuladas por
    // conversación, así que sin restar se informaría también lo que costó
    // llevarla hasta aquí.
    $delta = $ahora;

    if ($antes !== NULL) {
      foreach ($ahora as $clave => $valor) {
        $delta[$clave] = $valor - $antes[$clave];
      }
    }

    $costeDelDisparo += (float) $delta['usd'];
    $costeAcumulado += (float) $ahora['usd'];
    $respuestas = $estados[$id]['respuestas'] - ($base2[$id] ?? 0);

    if ($respuestas === 0) {
      $sinRespuesta[] = $id;
    }
    elseif ($respuestas > 1) {
      $duplicadas[] = $id . ' (' . $respuestas . ')';
    }

    printf("· %s · sesión %d · estado %s\n", $porSesion[$id] ?? 'cuenta ?', $id, $estados[$id]['estado']);

    if (isset($hitos[$id], $encolados[$id])) {
      $espera = $hitos[$id]['reservado'] === NULL ? NULL : $hitos[$id]['reservado'] - $encolados[$id];
      $duracion = ($hitos[$id]['reservado'] === NULL || $hitos[$id]['terminado'] === NULL)
        ? NULL
        : $hitos[$id]['terminado'] - $hitos[$id]['reservado'];

      // Que no se viera la reserva no significa que no la hubiera: si el turno
      // empezó y acabó entre dos sondeos, el elemento desapareció sin pasar por
      // un estado observable.
      $rapido = $hitos[$id]['reservado'] === NULL && $hitos[$id]['terminado'] !== NULL;

      if ($rapido) {
        // Sin haber visto la reserva, las dos cifras no se pueden separar, y
        // una etiqueta tiene que contar lo que dice contar.
        printf(
          "    encolado→final   %.0f s, espera y generación juntas (nunca se vio reservado)\n",
          $hitos[$id]['terminado'] - $encolados[$id],
        );
      }
      else {
        printf("    esperó           %s\n", $espera === NULL ? 'no lo tomó nadie mientras se miraba' : sprintf('%.0f s', $espera));
        printf("    generó durante   %s\n", $duracion === NULL ? 'no terminó mientras se miraba' : sprintf('%.0f s', $duracion));
      }
    }

    printf(
      "    %-16s %d%s\n",
      $hayBase ? 'respuestas' : 'respuestas (total)',
      $respuestas,
      $respuestas === 1 || !$hayBase ? '' : '  ← REVISAR',
    );
    printf(
      "    llamadas         %d al modelo · %d búsquedas (%s caracteres)\n",
      $delta['llamadas'],
      $delta['busquedas'],
      number_format($delta['caracteres']),
    );
    printf(
      "    tokens           %s de entrada (%d %% de caché) · %s de salida\n",
      number_format($delta['entrada']),
      $delta['entrada'] > 0 ? (int) round(100 * $delta['cache'] / $delta['entrada']) : 0,
      number_format($delta['salida']),
    );

    if ($antes === NULL || $antes['usd'] <= 0.0) {
      printf("    coste            %.4f USD\n\n", (float) $delta['usd']);
    }
    else {
      // Las dos cifras juntas, porque las dos se usan para cosas distintas: la
      // del turno para comparar turnos, y la de la conversación para el tope.
      printf(
        "    coste            %.4f USD este turno · %.4f en toda la conversación\n\n",
        (float) $delta['usd'],
        (float) $ahora['usd'],
      );
    }
  }

  print "═══ LO QUE SE QUERÍA SABER ═══\n\n";

  // Paralelismo. Se informan DOS cifras, y la segunda no es un adorno: los
  // recogedores entran a los :00, :20 y :40, así que los tres turnos arrancan
  // escalonados unos 20 s. Para que exista un instante con LOS TRES a la vez,
  // el primero tiene que durar más de 40 segundos. Con un turno corto —el
  // agente pregunta en vez de investigar— el solape de los tres sale en cero
  // aunque los tres recogedores estén trabajando perfectamente, y ese cero se
  // leería como una avería. El solape de DOS distingue un caso del otro.
  $ventanas = [];

  foreach ($ids as $id) {
    if (isset($hitos[$id]) && $hitos[$id]['reservado'] !== NULL && $hitos[$id]['terminado'] !== NULL) {
      $ventanas[$id] = [$hitos[$id]['reservado'], $hitos[$id]['terminado']];
    }
  }

  if (count($ids) < 2) {
    // Con una sola conversación no hay concurrencia que medir, y una línea que
    // dice «no se puede decir» donde no había nada que decir se lee como si
    // algo hubiera fallado. `conversar` avanza de una en una.
    $ventanas = [];
  }
  elseif (count($ventanas) === count($ids)) {
    $solape = min(array_column($ventanas, 1)) - max(array_column($ventanas, 0));

    printf(
      "  En paralelo      %s\n",
      $solape > 0
        ? sprintf('SÍ: los %d se generaron a la vez durante %.0f s', count($ids), $solape)
        : sprintf('los %d a la vez, NO (faltaron %.0f s); mira la línea siguiente antes de concluir nada', count($ids), -$solape),
    );
  }
  elseif ($ids !== []) {
    $terminaronTodos = $hitos !== [] && array_filter(array_column($hitos, 'terminado')) !== [];

    printf(
      "  En paralelo      no se puede decir: %s\n",
      match (TRUE) {
        $hitos === [] => 'esto solo relee lo guardado; el reparto en el tiempo se mide al lanzar',
        $terminaronTodos && $ventanas === [] => 'los turnos empezaron y acabaron entre dos sondeos (motor simulado)',
        default => 'alguno no llegó a terminar mientras se miraba',
      },
    );
  }

  // Dos a la vez: TODAS las parejas que coincidieron, no solo la mejor. Basta
  // una para demostrar que hay más de un procesador generando, que es la
  // pregunta de fondo; y enseñarlas todas evita la impresión de que solo hubo
  // un par de suerte. El 23-09-2026 el informe citó una pareja de 6 s cuando
  // había otra de 5, y hubo que sacarla del registro a mano.
  $parejas = [];
  $nombres = array_column($turnos, 'cuenta', 'sesion');

  foreach (array_keys($ventanas) as $uno) {
    foreach (array_keys($ventanas) as $otro) {
      if ($uno >= $otro) {
        continue;
      }

      $coincidencia = min($ventanas[$uno][1], $ventanas[$otro][1]) - max($ventanas[$uno][0], $ventanas[$otro][0]);

      if ($coincidencia > 0) {
        $parejas[sprintf(
          '%s+%s %.0f s',
          $nombres[$uno] ?? $uno,
          $nombres[$otro] ?? $otro,
          $coincidencia,
        )] = $coincidencia;
      }
    }
  }

  if (count($ventanas) > 1) {
    arsort($parejas);

    printf(
      "  Dos a la vez     %s\n",
      $parejas === []
        ? 'NO: ni siquiera dos coincidieron, así que se atendieron en serie'
        : 'sí: ' . implode(' · ', array_keys($parejas)),
    );
  }

  printf(
    "  Duplicados       %s\n",
    match (TRUE) {
      !$hayBase => 'no se puede decir: sin la foto de antes, lo que se cuenta es el total',
      $duplicadas === [] => 'ninguno: una respuesta nueva por conversación',
      default => 'HAY: ' . implode(' · ', $duplicadas),
    },
  );

  if ($sinRespuesta !== []) {
    printf("  Sin respuesta    %s\n", implode(',', $sinRespuesta));
  }

  $memoria = $hitos === [] ? NULL : reset($hitos)['memoria'] ?? NULL;

  if ($memoria === NULL) {
    print "  Memoria          no medida (ps no disponible, o ningún proceso de drush a la vista)\n";
  }
  elseif ($memoria['cuantos'] > 1) {
    printf(
      "  Memoria          hasta %.1f MB entre %d procesos de drush a la vez\n",
      $memoria['suma'],
      $memoria['cuantos'],
    );
    printf("                   el mayor, %.1f MB: %s\n", $memoria['mayor'], $memoria['que']);
  }
  else {
    printf("  Memoria          %.1f MB en un solo proceso: %s\n", $memoria['mayor'], $memoria['que']);
  }

  printf("  Coste del disparo %.4f USD: SOLO los turnos que se acaban de medir\n", $costeDelDisparo);

  if (abs($costeAcumulado - $costeDelDisparo) > 0.0001) {
    printf("  Coste acumulado  %.4f USD en estas conversaciones, preparación incluida\n", $costeAcumulado);
  }

  print "  (las búsquedas del buscador van aparte y no se pueden medir desde aquí)\n";

  $global = \Drupal::service(SpendGuard::class)->statusGlobal();

  if ($global !== NULL) {
    printf("  Gasto global     %.2f de %.2f USD este mes\n", $global['spent'], $global['limit']);
  }

  print "\nNada se ha borrado: las conversaciones son reales y se ven en Consumo.\n";
}

// El guion empieza aquí.
[$accion, $sueltos, $opciones, $gasta] = ensayo_argumentos($extra ?? []);

$simulado = Settings::get(DiagnosticEngineFactory::MOCK_SETTING) === TRUE;
$agenteId = $opciones['agente'] ?? (string) ensayo_por_defecto('agente');
$agente = \Drupal::entityTypeManager()->getStorage('sld_agent')->load($agenteId);

if ($accion !== 'ayuda' && $agente === NULL) {
  printf("No existe el agente «%s».\n", $agenteId);

  return;
}

$conSesiones = ensayo_ids((string) ($opciones['sesiones'] ?? ''));
$necesitaCuentas = $accion === 'cupo' || $accion === 'crear' || ($accion === 'lanzar' && $conSesiones === []);

if ($necesitaCuentas) {
  $encontradas = ensayo_cuentas($opciones['cuentas'] ?? (string) ensayo_por_defecto('cuentas'));

  if ($encontradas['faltan'] !== []) {
    printf("No existen estas cuentas: %s\n", implode(', ', $encontradas['faltan']));
    print "\nSi son las del ensayo, se crean una vez y se quedan (no son alumnos,\n";
    print "no entran por WordPress y no tienen curso: solo sirven para medir):\n\n";

    foreach ($encontradas['faltan'] as $nombre) {
      printf(
        "  drush user:create %s --mail=\"%s@ensayo.invalid\" --password=\"$(openssl rand -base64 18)\"\n",
        $nombre,
        $nombre,
      );
    }

    print "\nY conviene darles la zona horaria de los alumnos, porque la semana de\n";
    print "investigación se calcula en la de cada quien:\n\n";
    printf(
      "  drush php:eval 'foreach ([%s] as \$n) { \$c = user_load_by_name(\$n); \$c->set(\"timezone\", \"America/Mexico_City\")->save(); }'\n",
      implode(', ', array_map(static fn (string $n): string => '"' . $n . '"', $encontradas['faltan'])),
    );

    return;
  }

  $buscador = ensayo_buscador_encendido();
  $estados = [];

  foreach ($encontradas['cuentas'] as $nombre => $cuenta) {
    $estados[$nombre] = ensayo_estado_de_cuenta($cuenta, $agente, $buscador);
  }
}

if ($accion === 'cupo') {
  printf(
    "Agente: %s · busca: %s · buscador del sitio: %s · motor: %s\n",
    $agente->label(),
    $agente->canSearch() ? 'sí' : 'NO',
    $buscador ? 'encendido' : 'APAGADO',
    $simulado ? 'SIMULADO (no se paga)' : 'REAL (se paga)',
  );
  print "Esta orden solo lee: no crea conversaciones, no escribe cupos y no llama a nadie.\n";
  ensayo_imprimir_cupo($estados);

  $reparos = ensayo_reparos($estados, $agente, $buscador, ($opciones['forzar'] ?? '') === 'si');

  print "\n";
  print $reparos === []
    ? "Se puede lanzar.\n"
    : "NO se puede lanzar todavía:\n  · " . implode("\n  · ", $reparos) . "\n";

  return;
}

if ($accion === 'crear') {
  print "Creo una conversación por cuenta y no mando ningún mensaje: esto no gasta.\n\n";

  $ids = [];

  foreach ($encontradas['cuentas'] as $nombre => $cuenta) {
    $sesion = ensayo_crear_sesion($cuenta, $agente);
    $ids[] = (int) $sesion->id();

    printf("  %s → sesión %s\n", $nombre, $sesion->id());
  }

  printf("\nSesiones: %s\n\n", implode(',', $ids));
  print "Ahora se conversa una por una, hasta que el agente esté a punto de\n";
  print "investigar. Cada respuesta suya se lee antes de contestarle:\n\n";
  printf("  conversar %d \"lo que le contestas\" SI-GASTA\n", $ids[0] ?? 0);
  print "\nY cuando las tres estén en ese punto, el disparo simultáneo:\n\n";
  printf("  lanzar SI-GASTA sesiones=%s mensaje=\"adelante, investiga\"\n", implode(',', $ids));

  return;
}

if ($accion === 'leer') {
  $ids = ensayo_ids((string) ($sueltos[0] ?? ''));

  if ($ids === []) {
    print "Hacen falta los ids de sesión, separados por comas.\n";

    return;
  }

  foreach ($ids as $id) {
    printf("\n═══ sesión %d ═══\n", $id);

    foreach (\Drupal::service(ConversationService::class)->getConversation($id) as $mensaje) {
      printf("\n### %s\n%s\n", strtoupper($mensaje->role->value), $mensaje->content);
    }
  }

  return;
}

if ($accion === 'conversar') {
  $id = (int) ($sueltos[0] ?? 0);
  $texto = trim((string) ($sueltos[1] ?? ''));

  if ($id === 0 || $texto === '') {
    print "Uso: conversar <sesión> \"lo que le contestas\" SI-GASTA\n";

    return;
  }

  if (!$simulado && !$gasta) {
    print "ABORTADO: un turno es una llamada al proveedor y se paga.\n";
    print "Si estás de acuerdo, repite añadiendo SI-GASTA.\n";

    return;
  }

  $sesion = \Drupal::entityTypeManager()->getStorage('sld_diagnostic_session')->load($id);

  if ($sesion === NULL) {
    printf("No existe la sesión %d.\n", $id);

    return;
  }

  if (!$sesion->getStatus()->acceptsMessages()) {
    printf("La sesión %d está en «%s» y no admite mensajes.\n", $id, $sesion->getStatus()->value);

    return;
  }

  // Esta parte NO mide concurrencia: avanza la conversación. Por eso va de una
  // en una y se espera a que los recogedores del servidor hagan su trabajo, en
  // lugar de drenar la cola aquí: drenarla procesaría también el turno de
  // cualquier alumno que estuviera esperando, dentro de este proceso y con la
  // identidad equivocada.
  // La foto de cuántas respuestas hay se toma ANTES de mandar, o la del propio
  // turno contaría como preexistente y el duplicado se volvería invisible.
  $turnos = ensayo_turnos_de_sesiones([$id]);
  $respuesta = \Drupal::service(ConversationService::class)->submitMessage($sesion, $texto);
  // El instante que vale es este: cuando el elemento ya está en la cola.
  $turnos[0]['encolado'] = microtime(TRUE);

  if (!empty($respuesta['processing'])) {
    $hitos = ensayo_vigilar($turnos, (int) ($opciones['vigilar'] ?? 300));
    ensayo_informe([$id], $hitos, $turnos);
  }
  else {
    print "\nEse turno no pasó por la cola: se generó en el acto.\n";
    ensayo_informe([$id], [], $turnos);
  }

  print "\nOJO: las cifras de arriba son de TODA la conversación, no de este turno.\n";
  print "\n─── lo último que dijo el agente ───\n\n";
  print ensayo_ultima_respuesta($id) . "\n";

  return;
}

if ($accion === 'lanzar') {
  if (!$simulado && !$gasta) {
    print "ABORTADO: esto llama al proveedor con dinero real, una vez por cuenta.\n";
    print "Mira antes «cupo». Si estás de acuerdo, repite con SI-GASTA al final.\n";

    return;
  }

  print $simulado
    ? "MOTOR SIMULADO: ensayo en seco. No se paga nada y los tiempos no significan nada.\n"
    : "MOTOR REAL: a partir de aquí se paga.\n";

  $buscador = ensayo_buscador_encendido();
  $aLanzar = [];

  // Dos maneras de llegar hasta aquí, y la segunda existe por lo que pasó el
  // 23-09-2026: con conversaciones nuevas el agente contesta PREGUNTANDO —no
  // investiga—, los turnos duran entre cuatro y ocho segundos y el primer
  // proceso que llega vacía la cola antes de que entre el siguiente recogedor.
  // El ensayo salió «en serie» sin que eso dijera nada de cuántos procesadores
  // hay. Para medir concurrencia hacen falta turnos largos, y los turnos largos
  // son los que investigan: hay que llevar cada conversación hasta ese punto
  // antes de disparar las tres a la vez.
  if ($conSesiones !== []) {
    $almacenSesiones = \Drupal::entityTypeManager()->getStorage('sld_diagnostic_session');
    $almacenCuentas = \Drupal::entityTypeManager()->getStorage('user');
    $estados = [];

    foreach ($conSesiones as $id) {
      $sesion = $almacenSesiones->load($id);

      if ($sesion === NULL) {
        printf("No existe la sesión %d.\n", $id);

        return;
      }

      if (!$sesion->getStatus()->acceptsMessages()) {
        printf(
          "La sesión %d está en «%s» y no admite mensajes: no se lanza nada.\n",
          $id,
          $sesion->getStatus()->value,
        );

        return;
      }

      $cuenta = $almacenCuentas->load((int) $sesion->getOwnerId());

      if ($cuenta === NULL) {
        printf("La sesión %d no tiene dueño legible.\n", $id);

        return;
      }

      $nombre = $cuenta->getAccountName();
      $estados[$nombre] = ensayo_estado_de_cuenta($cuenta, $agente, $buscador);
      $aLanzar[] = ['cuenta' => $nombre, 'usuario' => $cuenta, 'sesion' => $sesion];
    }
  }
  else {
    foreach ($encontradas['cuentas'] as $nombre => $cuenta) {
      $aLanzar[] = ['cuenta' => $nombre, 'usuario' => $cuenta, 'sesion' => NULL];
    }
  }

  $reparos = ensayo_reparos($estados, $agente, $buscador, ($opciones['forzar'] ?? '') === 'si');

  if ($reparos !== []) {
    print "\nABORTADO sin gastar un céntimo:\n  · " . implode("\n  · ", $reparos) . "\n";

    return;
  }

  $conversacion = \Drupal::service(ConversationService::class);
  $herramientas = \Drupal::service(ToolBoxFactory::class);
  $mensaje = trim((string) ($opciones['mensaje'] ?? ensayo_por_defecto('mensaje')));
  $turnos = [];

  print "\nEncolando…\n";

  foreach ($aLanzar as $fila) {
    // La última palabra la tiene quien decide de verdad, no la foto que se
    // tomó hace un momento: entre el «cupo» y este instante alguien pudo entrar
    // y gastar su misión. Se pregunta cuenta por cuenta, justo antes de mandar
    // el mensaje, porque un turno que no se encola se ejecuta aquí y se paga.
    if (!$herramientas->mayResearch((int) $fila['usuario']->id(), $agente->id())) {
      printf("\n  PARADA antes de gastar: %s ya no puede investigar. No se lanzan los demás.\n", $fila['cuenta']);

      break;
    }

    $sesion = $fila['sesion'] ?? ensayo_crear_sesion($fila['usuario'], $agente);
    $yaTenia = ensayo_respuestas_de((int) $sesion->id());
    $yaGastado = ensayo_metricas_de((int) $sesion->id());
    $antes = microtime(TRUE);
    $respuesta = $conversacion->submitMessage($sesion, $mensaje);

    $turnos[] = [
      'sesion' => (int) $sesion->id(),
      'cuenta' => $fila['cuenta'],
      'encolado' => microtime(TRUE),
      'antes' => $yaTenia,
      'metricas' => $yaGastado,
    ];

    printf("  %s → sesión %d en %.2f s\n", $fila['cuenta'], $sesion->id(), microtime(TRUE) - $antes);

    // Si este turno NO se encoló, se acaba de ejecutar aquí mismo y se ha
    // pagado. Se para en seco en lugar de repetirlo con las demás: el ensayo
    // ya no mide lo que decía medir, y seguir solo añadiría gasto.
    if (empty($respuesta['processing'])) {
      print "\n  PARADA: ese turno no se encoló, se ejecutó al vuelo. No se lanzan los demás.\n";
      print "  Revisa «cupo»: alguna cuenta perdió su capacidad de investigar entre una cosa y otra.\n";

      break;
    }
  }

  $ids = array_column($turnos, 'sesion');
  $separacion = count($turnos) < 2 ? 0 : end($turnos)['encolado'] - reset($turnos)['encolado'];

  printf("\nSesiones: %s\n", implode(',', $ids));
  printf("Del primero al último: %.2f s.\n", $separacion);

  $hitos = ensayo_vigilar($turnos, (int) ($opciones['vigilar'] ?? ensayo_por_defecto('vigilar')));

  ensayo_informe($ids, $hitos, $turnos);

  printf("\nPara volver a ver estas cifras: informe %s\n", implode(',', $ids));

  return;
}

if ($accion === 'informe') {
  $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($sueltos[0] ?? '')))));

  if ($ids === []) {
    print "Hacen falta los ids de sesión que imprimió «lanzar».\n";

    return;
  }

  ensayo_informe($ids, [], ensayo_turnos_de_sesiones($ids, FALSE));

  return;
}

print <<<AYUDA
Mide tres investigaciones simultáneas en producción. GASTA DINERO.

  cupo                        qué puede cada cuenta esta semana. Solo lee.
  crear                       una conversación por cuenta, sin mensajes. No gasta.
  conversar <id> "…" SI-GASTA  avanza UNA conversación y enseña qué contestó.
  leer <ids>                  vuelca las conversaciones enteras.
  lanzar SI-GASTA             encola un turno por cuenta y se queda mirando.
  informe <ids>               vuelve a sacar las cifras de un ensayo ya corrido.

Opciones: cuentas= sesiones= agente= mensaje= vigilar= forzar=si

Para medir concurrencia hacen falta turnos LARGOS, y los largos son los que
investigan. Con una conversación nueva el agente contesta preguntando y el turno
dura segundos, así que el camino es: crear · conversar hasta que esté a punto de
investigar · lanzar con sesiones= para disparar las tres a la vez.

El detalle —qué mide cada número, y qué NO mide— está en la cabecera de este
archivo.

AYUDA;
