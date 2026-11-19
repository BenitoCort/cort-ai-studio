# Cort AI Studio 1.3.0

Plugin de WordPress para generar borradores de blog y crear o modificar páginas nativas de Elementor mediante propuestas con vista previa, aprobación y respaldo. Requiere WordPress 6.5 o posterior, PHP 8.1 o posterior y un proyecto de OpenAI API con acceso a los modelos elegidos. Elementor es opcional para Blog Writer y necesario para AI Page Builder.

La navegación incluye Dashboard, AI Blog Writer, AI Page Builder, Coming Soon y Settings. AI Page Builder planifica la dirección visual antes del contenido y genera composiciones, tarjetas y estilos responsive nativos de Elementor. La versión 1.3 detecta los widgets por sus controles registrados, permite los compatibles de PRO Elements sin exigir Elementor Pro oficial y muestra el inventario en el panel. Consulta [la arquitectura, compatibilidad, limitaciones, pruebas e instrucciones de AI Page Builder](docs/page-builder.md). Atomic V4 continúa excluido; se admiten estructuras clásicas y Flexbox con verificación de guardado.

## Instalar y configurar

1. En **Plugins → Añadir nuevo → Subir plugin**, carga el ZIP de **Cort AI Studio** y activa el plugin.
2. Abre **Cort AI Studio → Settings**. Introduce una clave de proyecto de OpenAI API y guarda los ajustes.
3. Presiona **Test Connection**. La prueba verifica la autenticación y el acceso al modelo de texto predeterminado. El acceso a búsqueda e imágenes se comprueba cuando se usan esas funciones.
4. Completa **Website Content Profile** con los servicios, el público, las ubicaciones, la voz y la llamada a la acción del negocio. Usa información confirmada, apta para enviar a OpenAI durante la generación.
5. Abre **AI Blog Writer**, introduce el tema y elige el modelo, la extensión y las opciones. Presiona **Generate Article**.
6. Abre el resultado con **Edit Post** o **Preview Post**. Revisa el texto, las citas, las imágenes y los metadatos. Publica desde el editor de WordPress cuando esté listo.

Para configurar la clave desde el servidor, puedes definir esta constante en **wp-config.php** antes de la línea que carga **wp-settings.php**. La constante tiene prioridad sobre la clave guardada en Settings.

~~~php
define('CORT_AI_STUDIO_OPENAI_API_KEY', 'TU_CLAVE_API_DE_OPENAI');
~~~

La clave guardada en Settings se almacena en una opción de WordPress sin autoload. El panel muestra un valor enmascarado. Las solicitudes a OpenAI salen del servidor PHP. El generador está disponible para administradores y editores, y los ajustes requieren permiso para administrar opciones. La generación de imágenes también exige permiso para subir archivos.

## Funciones incluidas

| Función | Comportamiento |
| --- | --- |
| Generación de artículos | GPT-6 Luna y GPT-6.1 Terra admiten Medium, High y Max. El artículo puede escribirse en inglés o español. La longitud personalizada admite de 300 a 3,000 palabras como objetivo. |
| Edición en WordPress | Crea una entrada de tipo **post**, en estado **Draft**, con slug, introducción, encabezados, listas, conclusión y llamada a la acción. WordPress controla la publicación manual. |
| Enlaces internos | Selecciona hasta diez entradas o páginas relacionadas, publicadas y sin contraseña. Revalida su estado y URL cuando guarda el borrador. |
| Búsqueda externa | Ejecuta una etapa opcional de búsqueda web. El borrador solo puede enlazar citas devueltas por la búsqueda. El resultado muestra un aviso si la investigación falla o no aporta citas utilizables. |
| SEO y taxonomías | Limita el título SEO a 50 caracteres y la descripción a 150. Guarda ambos en Yoast SEO o Rank Math si están activos y en metadatos propios. Con el SEO propio, el panel del borrador permite editar o copiar esos campos. Usa categorías existentes, muestra las sugerencias y asigna etiquetas. |
| Imágenes | GPT Image 2.6 Flare genera hasta una destacada y dos para el contenido. Admite calidad Low, Medium y High, con formato horizontal, cuadrado o vertical. Guarda JPEG con título y texto alternativo en la Biblioteca de medios. |
| Consumo | Registra el usuario, la fecha, el modelo, el estado, el artículo, los tokens, las imágenes, las búsquedas y el costo estimado en USD. El Dashboard muestra el historial y los totales. |
| AI Page Builder | Planificación visual y composición en dos solicitudes, jerarquía tipográfica, once patrones de sección, tarjetas, overlays, gradientes, hover y movimiento reducido. Tres tamaños responsive, selección contextual de medios sin repetición por defecto y comprobaciones antes del guardado. Permite elegir Default, Full Width o Canvas para páginas nuevas; conserva el layout de las existentes. |

El generador inicia con SEO y enlaces internos activados. La búsqueda externa y las imágenes se activan en cada artículo. El título y la descripción SEO propios quedan disponibles para edición y copia. Su publicación en el HTML público se gestiona desde un plugin SEO o la configuración del tema.

## Recuperar una generación y controlar el costo

La generación avanza por solicitudes independientes mientras **AI Blog Writer** está abierto. Si sales, puedes reabrirlo y elegir **Resume generation**. El sitio admite una generación activa a la vez. El autor de la solicitud y los administradores pueden retomarla. El borrador se guarda antes de pedir las imágenes. Si alguna falla, el resultado muestra el aviso y conserva los pasos completados. Una imagen generada que no se pueda insertar automáticamente sigue disponible en la Biblioteca de medios.

El límite inicial es **0.75 USD por artículo**. Puedes ajustarlo en **Settings → Images and spending**. El valor 0 desactiva el control. El plugin comprueba la estimación antes de iniciar solicitudes facturables. Puedes actualizar las tarifas en **Cost estimate rates**. Los tokens medidos y las tarifas configuradas calculan el importe mostrado. La búsqueda, las imágenes y las solicitudes con uso incompleto pueden hacer variar la facturación real.

Las etapas de texto y búsqueda solicitan el modo de ejecución en segundo plano de OpenAI con `store: false`. Ese modo conserva temporalmente los datos necesarios para recuperar la respuesta, de acuerdo con las políticas del proyecto. La etapa de imagen sigue sujeta al tiempo máximo que permita el hosting por solicitud. [Modo en segundo plano de OpenAI](https://developers.openai.com/api/docs/guides/background) · [Precios de OpenAI](https://developers.openai.com/api/docs/pricing)

## Arquitectura y desarrollo

| Archivo o módulo | Responsabilidad |
| --- | --- |
| `cort-ai-studio.php`, `src/Plugin.php` | Arranque, activación, carga de clases y registro de módulos. |
| `src/Admin.php`, `src/Settings.php`, `src/Rest.php` | Panel, preferencias del sitio, permisos y rutas REST autenticadas con nonce. |
| `src/OpenAI.php`, `src/Sources.php`, `src/Article.php` | Transporte HTTP, selección de fuentes, esquema JSON y render de bloques. |
| `src/Writer.php`, `src/Jobs.php`, `src/Usage.php` | Flujo por etapas, recuperación, persistencia y cálculo de consumo. |
| `src/Drafts.php`, `src/Images.php` | Entradas, metadatos SEO, taxonomías y archivos de medios de WordPress. |
| `views/`, `assets/` | Páginas administrativas y controles del navegador. |

El código PHP usa el namespace `CortAIStudio`. La activación crea una tabla con el prefijo de cada sitio para rastrear y recuperar operaciones. Las llamadas a OpenAI pasan por `OpenAI`. Los modelos y los idiomas de contenido se registran en `Settings`. Los módulos nuevos pueden registrarse en `Plugin::boot()` con rutas y permisos propios.

Para trabajar en el código, copia el directorio `cort-ai-studio` a `wp-content/plugins` de un entorno de desarrollo. La carpeta completa es la raíz que debes conservar al empaquetar el ZIP. El plugin carga PHP, JavaScript y CSS directamente desde sus archivos y funciona con la instalación estándar de WordPress.

~~~bash
find cort-ai-studio -name '*.php' -type f -exec php -l {} \;
node --check cort-ai-studio/assets/admin.js
~~~

## Pruebas y validación en tu sitio

Se comprobó la sintaxis de PHP 8.1 y JavaScript, y se ejecutó el flujo de integración en **WordPress 6.5.5 con PHP 8.1.34**. Las pruebas de WordPress usaron respuestas de prueba controladas para verificar la creación del borrador y de sus adjuntos, bloques, SEO, enlaces permitidos, permisos, nonces, control de duplicados, presupuesto y recuperación ante fallos. Gutenberg validó los 43 bloques del borrador de prueba. Yoast SEO 23.5 y Rank Math 1.0.239 leyeron de sus campos nativos el título y la descripción generados. Las pruebas del JavaScript del panel comprobaron los controles del formulario, el resultado y el enmascarado de la clave.

La ejecución real con una clave de OpenAI sigue pendiente de validación. Para probarla, guarda tu clave y usa **Test Connection**. Genera un artículo corto con **Luna / Medium** y verifica el borrador y sus métricas. Luego genera otro con **Terra**, búsqueda externa y una imagen destacada. Comprueba las citas, el título y el texto alternativo del archivo en la Biblioteca de medios, y la vista previa del artículo. Si tienes Yoast o Rank Math, abre su panel en el borrador para revisar los campos SEO.
