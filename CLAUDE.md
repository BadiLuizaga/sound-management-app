# SOUND POS — instrucciones del proyecto

Reglas obligatorias para todo el desarrollo restante. Prevalecen sobre cualquier hábito por defecto.

## 1. Forma de trabajo

- Se trabaja **por pasos autorizados** (p. ej., «Etapa 4 — paso 4»). Cada paso requiere autorización expresa; al terminarlo, **detenerse y esperar revisión**. Nunca avanzar al paso siguiente por iniciativa propia.
- El alcance vigente de la Etapa 4 es la **§12 de `ETAPA_4_DIAGNOSTICO_RESERVAS.md`**, que prevalece sobre el resto del documento.
- **Nunca se reintroducen** (§12.6): garantías ni depósitos; reembolsos ni ninguna devolución de dinero; horas adicionales, tolerancias, recargos por retraso ni control de atrasos; reportes de conciliación.
- Prioridad del desarrollo:
  1. Implementación correcta.
  2. Integridad de datos y reglas de negocio.
  3. Validaciones específicas y necesarias.
  4. Documentación de resultados.

## 2. Restricciones permanentes

- No modificar las migraciones existentes (los cambios de esquema van en migraciones nuevas y reversibles).
- No modificar `composer.json` ni `composer.lock`; no instalar dependencias.
- No realizar operaciones Git.
- No modificar funcionalidades ya aprobadas salvo dependencia indispensable y justificada.
- Ninguna instrucción se interpreta como autorización para eliminar datos existentes, modificar migraciones, cambiar dependencias o realizar operaciones destructivas.

## 3. Datos de prueba (máximo 5 por módulo)

- Como máximo **5 registros de prueba por módulo**, y solo cuando sean realmente necesarios.
- Reutilizar los mismos registros siempre que sea posible; no repetir inserciones para escenarios similares.
- No generar cientos o miles de registros ficticios ni datos de demostración sin autorización.

## 4. Ejecución de pruebas (solo las mínimas necesarias)

- **Primero revisar** el código, la lógica, las relaciones y las validaciones; después, probar.
- Ejecutar **únicamente las pruebas específicas indispensables** para verificar el cambio realizado.
- **No** ejecutar suites completas, regresiones generales ni pruebas masivas automáticamente en cada etapa.
- Pedir autorización antes de cualquier prueba con múltiples repeticiones o que genere muchos registros (p. ej., las pruebas de concurrencia real en MySQL).
- No volver a ejecutar pruebas ya aprobadas, salvo que un cambio posterior afecte directamente su funcionamiento.
- Al informar, distinguir los **21 fallos heredados de Breeze** (`tests/Feature/Auth/*`, `tests/Feature/ProfileTest.php`, `tests/Feature/ExampleTest.php`) de cualquier error nuevo.

## 5. Bases de datos

- **`sound_management_db` (base real): nunca insertar datos ficticios.**
- Validaciones MySQL autorizadas: **solo** `sound_management_test` en `127.0.0.1:3310`, usuario `sound_test` (scripts `.tools\validacion-mysql-etapa4.ps1` y `.tools\ejecutar-pruebas.ps1 -Motor mysql`, que verifican el destino y abortan si difiere).
- SQLite de pruebas: solo `:memory:` (definido en `phpunit.xml`).
- No dejar acumulaciones de registros de prueba: al terminar una validación, eliminar los registros temporales creados, conservando la estructura y las configuraciones indispensables.
- **Nunca ejecutar limpiezas destructivas sin mostrar antes qué tablas y registros se modificarán** y obtener autorización.

## 6. Convenciones de dinero y tiempo

- Importes en **centavos enteros** (`Modules\Finance\Support\Dinero`), moneda **BOB**.
- Zona horaria **America/La_Paz**.
