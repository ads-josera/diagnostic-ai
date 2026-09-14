# 0019 — La suscripción

**13-09-2026**

## Por qué existe

Lo pidió el cliente a través de José Raúl: que a los dos agentes se pueda
entrar también con una **suscripción, mensual o anual**, además de por curso.
Los precios irían por segmento —cliente nuevo, cliente actual de Salesbumm,
alumni de Sales Leadership Architect™— y todavía no están cerrados. Los topes
de uso siguen siendo los de Drupal, sin cambios. Y el acceso por curso tiene
que seguir funcionando exactamente igual.

## Lo que se hizo

**El puente entre el cobro y el acceso es un curso de LearnDash.** El cliente
ya tiene Stripe, el plugin de membresías de WooCommerce, la integración de
LearnDash con WooCommerce y la licencia de WooCommerce Subscriptions sin
activar. Con eso:

1. WooCommerce Subscriptions cobra cada mes o cada año.
2. Cada producto de suscripción lleva asociado un curso de LearnDash,
   «Suscripción Salesbumm AI». La integración concede ese curso mientras la
   suscripción está al corriente y lo retira al cancelarse o dejar de pagarse.
3. El plugin puente (1.3.0) tiene un campo nuevo, «Cursos de suscripción».
   Quien tiene uno de esos cursos recibe **todos** los cursos que dan agente, y
   sin fecha de fin.

**Drupal no cambia.** Recibe lo mismo que para un alumno que hubiera comprado
los dos cursos, con `expires_at` vacío, y eso ya lo sabía tratar: concede cada
agente por su curso y no enseña aviso de caducidad.

## Decisiones no obvias

**El plugin no habla con WooCommerce.** Podría preguntar por el estado de la
suscripción, pero ataría el plugin a WooCommerce Subscriptions y a sus estados
—activa, en espera, pendiente de cancelación—, que ya interpreta la
integración. Mirando solo el curso, el plugin sigue dependiendo únicamente de
LearnDash, y cambiar la forma de cobrar no obliga a tocarlo.

**La suscripción no tiene reloj propio.** El reloj de doce meses existe porque
el curso no caduca; la suscripción sí caduca sola. Añadirle un reloj habría
cerrado el acceso a quien sigue pagando.

**Quien tiene las dos cosas no gana ni pierde nada.** Si compró el curso, su
reloj arranca y corre igual que si no estuviera suscrito. Si no arrancara
durante la suscripción, al cancelarla empezaría a contar de cero y le regalaría
otro año.

**Un curso en las dos listas cuenta como suscripción.** Como curso normal
arrancaría el reloj y cerraría a los doce meses a alguien que sigue pagando.
La pantalla de ajustes lo señala.

**Un curso de suscripción Abierto o Gratis se ignora.** En LearnDash un curso
Abierto lo tiene todo usuario con sesión y uno Gratis quien pulse
«inscribirse». Un descuido al crearlo regalaría los dos agentes a todo el
sitio; el plugin no lo acepta y la pantalla de ajustes dice por qué. A los
cursos que dan acceso no se les aplica: siguen como estaban.

## Cómo se comprobó que no se rompió lo anterior

El plugin no tenía pruebas. Antes de tocarlo se escribieron once contra la
1.2.0 —quien compra entra, a los doce meses se cierra, al volver a comprar se
reinicia, un curso cualquiera del catálogo no reinicia nada— y se guardaron en
un commit aparte (`dd67900`). Siguen pasando con la 1.3.0. Las diez de la
suscripción se comprobaron también contra el código viejo: fallan, que es lo
que demuestra que prueban algo.

## Lo que queda del lado del cliente

- Activar WooCommerce Subscriptions y crear los productos, con sus precios.
- **Quién puede comprar cada segmento.** El precio de «cliente actual» o de
  «alumni» no debe poder comprarlo cualquiera. Es un asunto de la tienda, no del
  plugin; su plugin de membresías de WooCommerce puede restringir qué productos
  ve cada quien.
- Confirmar con una suscripción de prueba que, al fallar un pago, la
  integración retira el curso: el comportamiento exacto ante una suscripción
  «en espera» depende de la configuración de su tienda.
