<?php

/**
 * @file
 * Corre varias misiones Discovery seguidas y mide lo que cuestan.
 *
 * Es lo que pide el §9 de la especificación del cliente: los topes se calibran
 * por benchmark, no por decreto. Los que van puestos hoy salieron de UNA misión
 * medida, y una misión no dice dónde está el techo: dice dónde estaba ese día.
 *
 * **Cada misión es una llamada real y varias búsquedas reales. GASTA DINERO.**
 * A los precios medidos, una misión ronda los $0.45–0.60 USD. El tope global de
 * la pantalla de consumo es la red: si se agota, las llamadas dejan de ocurrir
 * y el benchmark se detiene solo en vez de vaciar la cuenta.
 *
 * Dos cosas se apartan de producción a propósito, y conviene tenerlas
 * presentes al leer los números:
 *
 *  - **Se devuelve el entitlement entre misiones.** En producción es uno por
 *    persona y semana; sin devolverlo solo se podría medir una cada siete días.
 *  - **Una sola persona corre todas.** Eso mantiene la caché del prompt
 *    caliente entre misiones, igual que le pasa a un alumno que vuelve, pero
 *    NO representa el primer turno de alguien que llega de cero. Ese primer
 *    turno se mide aparte y es la cifra que hay que sumar.
 *
 * Uso:
 * @code
 *   ddev drush php:script bin/benchmark.php -- correr 5
 *   ddev drush php:script bin/benchmark.php -- correr 5 5
 *   ddev drush php:script bin/benchmark.php -- informe
 * @endcode
 */

declare(strict_types=1);

use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Service\Conversation\ConversationService;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticPromptManager;
use Drupal\user\Entity\User;

/**
 * Los escenarios del benchmark.
 *
 * Veinte, y distintos entre sí a propósito: sector, territorio y tamaño de
 * operación. Repetir el mismo veinte veces mediría una cosa veinte veces, y lo
 * que hace falta saber es cuánto varía una misión según lo que se le pida.
 *
 * @return array<int, array{empresa: string, oferta: string, territorio: string, sectores: string}>
 *   Un escenario por entrada.
 */
function sld_bench_escenarios(): array {
  return [
    [
      'empresa' => 'Vialta Telematica',
      'oferta' => 'telemetria para flotillas pesadas: GPS, sensores de combustible y camaras de cabina. No vendemos por debajo de 60 unidades',
      'territorio' => 'Bajio: Queretaro, Guanajuato y Aguascalientes',
      'sectores' => 'manufactura, alimentos y bebidas, transporte de carga',
    ],
    [
      'empresa' => 'Almatek Systems',
      'oferta' => 'automatizacion de almacen: pallet shuttles y software de gestion. Proyectos desde 4 millones de pesos',
      'territorio' => 'Nuevo Leon y Coahuila',
      'sectores' => 'retail, distribucion y comercio electronico',
    ],
    [
      'empresa' => 'Vibrantia Predictivo',
      'oferta' => 'mantenimiento predictivo con sensores de vibracion para maquinaria rotativa',
      'territorio' => 'Veracruz y Tabasco',
      'sectores' => 'petroquimica, energia y quimica',
    ],
    [
      'empresa' => 'Cadena Fria Nova',
      'oferta' => 'plataforma de trazabilidad para cadena de frio, con certificacion sanitaria',
      'territorio' => 'Jalisco y Michoacan',
      'sectores' => 'agroindustria, lacteos y exportacion de berries',
    ],
    [
      'empresa' => 'Muro OT Seguridad',
      'oferta' => 'ciberseguridad industrial OT: segmentacion de redes y monitoreo de planta',
      'territorio' => 'Estado de Mexico y CDMX',
      'sectores' => 'automotriz, autopartes y manufactura pesada',
    ],
    [
      'empresa' => 'Pronostica Demanda',
      'oferta' => 'software de planeacion de demanda con pronostico, para empresas de consumo',
      'territorio' => 'Mexico, con foco en el centro del pais',
      'sectores' => 'consumo masivo y farmaceutico',
    ],
    [
      'empresa' => 'Nomina Norte',
      'oferta' => 'servicios de nomina y administracion de personal para plantillas de mas de 500',
      'territorio' => 'Baja California y Sonora',
      'sectores' => 'maquila, electronica y dispositivos medicos',
    ],
    [
      'empresa' => 'Solaria Techo Industrial',
      'oferta' => 'paneles solares industriales en techo, con financiamiento a diez anos',
      'territorio' => 'Sonora y Chihuahua',
      'sectores' => 'mineria, cemento y manufactura intensiva en energia',
    ],
    [
      'empresa' => 'Lotea ERP',
      'oferta' => 'ERP de manufactura por lotes, implantacion en menos de seis meses',
      'territorio' => 'Puebla y Tlaxcala',
      'sectores' => 'textil, calzado y alimentos procesados',
    ],
    [
      'empresa' => 'Ciclo Agua Industrial',
      'oferta' => 'tratamiento y reuso de agua industrial, con obligacion de cumplimiento normativo',
      'territorio' => 'Guanajuato y Queretaro',
      'sectores' => 'curtiduria, quimica y alimentos',
    ],
    [
      'empresa' => 'Eleva Montacargas',
      'oferta' => 'flota de montacargas en arrendamiento con telemetria y mantenimiento incluido',
      'territorio' => 'Nuevo Leon',
      'sectores' => 'logistica, acero y distribucion',
    ],
    [
      'empresa' => 'Metrologia Exacta',
      'oferta' => 'laboratorio de metrologia y calibracion acreditado',
      'territorio' => 'Aguascalientes y San Luis Potosi',
      'sectores' => 'automotriz y aeroespacial',
    ],
    [
      'empresa' => 'Distribuye B2B',
      'oferta' => 'plataforma de comercio B2B para distribuidores, con catalogo y credito',
      'territorio' => 'Yucatan y Quintana Roo',
      'sectores' => 'materiales de construccion y ferreteria',
    ],
    [
      'empresa' => 'Vigia Analitica',
      'oferta' => 'seguridad patrimonial con videoanalitica y centro de monitoreo propio',
      'territorio' => 'Sinaloa y Nayarit',
      'sectores' => 'agroindustria, pesca y logistica',
    ],
    [
      'empresa' => 'Ahorro Compartido Energia',
      'oferta' => 'consultoria de eficiencia energetica con contratos por ahorro compartido',
      'territorio' => 'CDMX y Estado de Mexico',
      'sectores' => 'hoteleria, hospitales y centros comerciales',
    ],
    [
      'empresa' => 'Empaque Vivo',
      'oferta' => 'empaque sostenible a medida, con troquel propio y tirajes medianos',
      'territorio' => 'Jalisco',
      'sectores' => 'alimentos artesanales, tequila y cosmetica',
    ],
    [
      'empresa' => 'Cobot Integra',
      'oferta' => 'robots colaborativos para linea de ensamble, con integracion llave en mano',
      'territorio' => 'Guanajuato y Queretaro',
      'sectores' => 'autopartes y electrodomesticos',
    ],
    [
      'empresa' => 'Riesgo Claro',
      'oferta' => 'plataforma de gestion de riesgo crediticio para financieras medianas',
      'territorio' => 'Mexico',
      'sectores' => 'sofomes, uniones de credito y arrendadoras',
    ],
    [
      'empresa' => 'Clinilab Empresas',
      'oferta' => 'servicios de laboratorio clinico para empresas, con unidad movil',
      'territorio' => 'Nuevo Leon y Tamaulipas',
      'sectores' => 'industria pesada y maquila',
    ],
    [
      'empresa' => 'Subestacion Segura',
      'oferta' => 'mantenimiento de subestaciones electricas y pruebas de aceite',
      'territorio' => 'Coahuila y Durango',
      'sectores' => 'mineria, acero y parques industriales',
    ],
  ];
}

/**
 * Compone el mensaje que abre y cierra la misión.
 *
 * Uno solo, y no una conversación: el benchmark tiene que aplicar el MISMO
 * protocolo a las veinte. Una conversación distinta en cada una mediría la
 * conversación, no la misión.
 *
 * @param array{empresa: string, oferta: string, territorio: string, sectores: string} $escenario
 *   Escenario a plantear.
 */
function sld_bench_mensaje(array $escenario): string {
  return sprintf(
    'Soy director comercial de %s. Vendemos %s. Somos una empresa joven y no vas a '
    . 'encontrar nada publico de nosotros: usa esta descripcion como fuente de '
    . 'primera parte y no gastes busquedas en buscarnos. Battlefield: %s. '
    . 'Sectores: %s. Adelante con el screening y cierrame la mision con el '
    . 'Weekly GOLD Pack completo.',
    $escenario['empresa'],
    $escenario['oferta'],
    $escenario['territorio'],
    $escenario['sectores'],
  );
}

/**
 * La cuenta con la que se mide una misión concreta.
 *
 * **Una persona por misión**, y no una para todas. Se descubrió corriéndolo:
 * con una sola cuenta, el benchmark topó contra el tope por persona y mes en
 * la misión quince —220 búsquedas exactas— y las cinco siguientes salieron
 * vacías porque el gateway las denegó una por una.
 *
 * El tope hizo su trabajo; el que estaba mal era el método. Veinte misiones en
 * una persona son cinco veces lo que el sistema concede a nadie: el
 * entitlement da una por semana. En producción cada misión es de alguien
 * distinto, así que medirlo así también es más fiel.
 *
 * @param int $indice
 *   Escenario que va a correr esta cuenta.
 */
function sld_bench_alumno(int $indice): User {
  $nombre = 'benchmark_p' . $indice;

  $existentes = \Drupal::entityTypeManager()->getStorage('user')
    ->loadByProperties(['name' => $nombre]);

  if ($existentes !== []) {
    return reset($existentes);
  }

  $cuenta = User::create([
    'name' => $nombre,
    'status' => 1,
    'timezone' => 'America/Mexico_City',
  ]);
  $cuenta->save();

  return $cuenta;
}

$accion = $extra[0] ?? 'informe';
$bd = \Drupal::database();

if ($accion === 'correr') {
  $cuantas = max(1, min(20, (int) ($extra[1] ?? 1)));
  $desde = max(0, (int) ($extra[2] ?? 0));

  $agente = \Drupal::entityTypeManager()->getStorage('sld_agent')->load('prospecting_diagnostic');
  $prompt = \Drupal::service(DiagnosticPromptManager::class)->composeFor($agente);
  $conversacion = \Drupal::service(ConversationService::class);
  $almacen = \Drupal::entityTypeManager()->getStorage('sld_diagnostic_session');
  $escenarios = sld_bench_escenarios();

  for ($i = $desde; $i < $desde + $cuantas && $i < count($escenarios); $i++) {
    $uid = (int) sld_bench_alumno($i)->id();

    // Se le devuelve la semana. En producción es una misión por persona y
    // semana; sin esto una repetición del benchmark no mediría nada.
    $bd->delete('sld_research_entitlement')->condition('uid', $uid)->execute();

    $sesion = $almacen->create([
      'uid' => $uid,
      'wp_user_id' => '99002',
      'course_id' => $agente->getCourseId(),
      'agent' => 'prospecting_diagnostic',
      'diagnostic_version' => $agente->getVersion(),
      'prompt_snapshot' => $prompt,
      'prompt_hash' => hash('sha256', $prompt),
      'started_at' => \Drupal::time()->getRequestTime(),
    ]);
    $sesion->setStatus(DiagnosticStatus::InProgress);
    $sesion->save();

    $sid = (int) $sesion->id();
    $inicio = microtime(TRUE);

    try {
      $conversacion->submitMessage($sesion, sld_bench_mensaje($escenarios[$i]));

      $cola = \Drupal::service('queue')->get('sld_diagnostic_turn');
      $trabajador = \Drupal::service('plugin.manager.queue_worker')
        ->createInstance('sld_diagnostic_turn');

      while ($elemento = $cola->claimItem(3600)) {
        $trabajador->processItem($elemento->data);
        $cola->deleteItem($elemento);
      }
    }
    catch (\Throwable $e) {
      // Una misión que revienta no debe llevarse el benchmark por delante: se
      // anota y se sigue. Un benchmark de 20 que muere en la 3 no mide nada.
      printf("%2d. sesión %-4d FALLÓ: %s\n", $i + 1, $sid, mb_substr($e->getMessage(), 0, 90));
      continue;
    }

    $segundos = microtime(TRUE) - $inicio;

    $u = $bd->query('SELECT COUNT(*) n, COALESCE(SUM(cost_usd),0) usd FROM {sld_ai_usage} WHERE session_id = :s', [':s' => $sid])->fetchObject();
    $b = $bd->query(
      'SELECT COUNT(*) n, COALESCE(SUM(retrieved_chars),0) c
         FROM {sld_tool_call}
        WHERE session_id = :s AND allowed = 1 AND tool = :t',
      [':s' => $sid, ':t' => 'buscar_web'],
    )->fetchObject();
    $rid = $bd->query('SELECT id FROM {sld_diagnostic_result} WHERE session_id = :s', [':s' => $sid])->fetchField();

    $cuentas = 0;

    if ($rid) {
      $resultado = \Drupal::entityTypeManager()->getStorage('sld_diagnostic_result')->load($rid);
      $cuentas = count($resultado->getAccounts());
    }

    printf(
      "%2d. sesión %-4d %2d búsquedas %7s chars %2d llamadas %2d cuentas %5.0fs \$%.4f%s\n",
      $i + 1, $sid, (int) $b->n, number_format((int) $b->c), (int) $u->n,
      $cuentas, $segundos, (float) $u->usd, $rid ? '' : '  (sin cerrar)',
    );
  }

  return;
}

if ($accion === 'informe') {
  $filas = $bd->query(
    'SELECT s.id sid,
            (SELECT COUNT(*) FROM {sld_tool_call} t WHERE t.session_id = s.id AND t.allowed = 1 AND t.tool = :t) busquedas,
            (SELECT COALESCE(SUM(retrieved_chars),0) FROM {sld_tool_call} t WHERE t.session_id = s.id AND t.allowed = 1 AND t.tool = :t) chars,
            (SELECT COUNT(*) FROM {sld_ai_usage} u WHERE u.session_id = s.id) llamadas,
            (SELECT COALESCE(SUM(cost_usd),0) FROM {sld_ai_usage} u WHERE u.session_id = s.id) usd,
            (SELECT COUNT(*) FROM {sld_diagnostic_result} r WHERE r.session_id = s.id) cerro,
            (SELECT COUNT(*) FROM {sld_tool_call} t WHERE t.session_id = s.id AND t.denial_reason = :d) topada
       FROM {sld_diagnostic_session} s
      WHERE s.uid IN (SELECT uid FROM {users_field_data} WHERE name LIKE :n)
      ORDER BY s.id',
    [':t' => 'buscar_web', ':n' => 'benchmark_%', ':d' => 'tope_llamadas_periodo'],
  )->fetchAll();

  if ($filas === []) {
    print "Todavía no hay misiones medidas.\n";
    return;
  }

  // Se apartan las misiones que no midieron nada, y se dice cuántas y por qué.
  // Promediarlas con las buenas hundiría los percentiles y haría parecer que
  // una misión necesita menos búsquedas de las que necesita, que es justo el
  // error que un tope mal puesto convierte en investigación a medias.
  //
  // Los dos motivos son distintos y conviene no confundirlos:
  //
  //  - **Topada**: el benchmark chocó contra su propio tope por persona y mes.
  //    Es un fallo del método —veinte misiones no caben en una persona—, no de
  //    la misión.
  //  - **Sin cerrar**: el agente pidió un dato antes de investigar, casi
  //    siempre porque el escenario no decía de qué país era el territorio. Es
  //    conducta correcta suya y escenario mal escrito nuestro.
  $topadas = array_filter($filas, static fn ($f): bool => (int) $f->topada > 0);
  $sinCerrar = array_filter($filas, static fn ($f): bool => (int) $f->topada === 0 && (int) $f->cerro === 0);
  $validas = array_filter($filas, static fn ($f): bool => (int) $f->topada === 0 && (int) $f->cerro > 0);

  if ($validas === []) {
    print "Ninguna misión válida que medir.\n";
    return;
  }

  $descartadas = $filas;
  $filas = array_values($validas);

  $busquedas = array_map(static fn ($f): int => (int) $f->busquedas, $filas);
  $chars = array_map(static fn ($f): int => (int) $f->chars, $filas);
  $costes = array_map(static fn ($f): float => (float) $f->usd, $filas);

  sort($busquedas);
  sort($chars);
  sort($costes);

  // El valor por debajo del cual queda ese porcentaje de las misiones. Un tope
  // se pone en el percentil alto y no en la media: la media deja fuera a la
  // mitad de las misiones, y una que topa no avisa —el agente declara lo que
  // le falta y parece que ya está—.
  $percentil = static function (array $valores, float $p) {
    if ($valores === []) {
      return 0;
    }

    $i = (int) ceil($p * count($valores)) - 1;

    return $valores[max(0, min($i, count($valores) - 1))];
  };

  printf("BENCHMARK — %d misiones válidas de %d corridas\n", count($filas), count($descartadas));

  if ($topadas !== []) {
    printf("  %d apartadas: toparon el cupo por persona y mes (fallo del método)\n", count($topadas));
  }

  if ($sinCerrar !== []) {
    printf("  %d apartadas: el agente pidió un dato antes de investigar\n", count($sinCerrar));
  }

  print "\n";
  printf("%-14s %8s %8s %8s %8s\n", '', 'mínimo', 'mediana', 'p90', 'máximo');
  printf("%-14s %8d %8d %8d %8d\n", 'búsquedas', $busquedas[0], $percentil($busquedas, 0.5), $percentil($busquedas, 0.9), end($busquedas));
  printf("%-14s %8s %8s %8s %8s\n", 'caracteres', number_format($chars[0]), number_format($percentil($chars, 0.5)), number_format($percentil($chars, 0.9)), number_format(end($chars)));
  printf("%-14s %8.4f %8.4f %8.4f %8.4f\n", 'USD', $costes[0], $percentil($costes, 0.5), $percentil($costes, 0.9), end($costes));
  printf("\ngasto total del benchmark: \$%.4f USD\n", array_sum($costes));

  print "\nLos topes se ponen sobre el p90 con holgura, no sobre la media.\n";

  return;
}

print "Uso: correr <n> [desde] | informe\n";
