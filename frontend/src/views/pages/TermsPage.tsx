import { Link } from 'react-router-dom';
import LegalLayout from '../components/LegalLayout';
import { CONTACT_EMAIL } from '../legal';

/**
 * Términos y condiciones de uso. Describen las reglas que la app ya aplica
 * (aprobación de mecánicos por servicio, licencia vigente, pagos, PQR); si
 * cambian, se actualiza el texto y se sube POLICY_VERSION (views/legal.ts)
 * junto con el backend.
 */
export default function TermsPage() {
  return (
    <LegalLayout
      title="Términos y condiciones"
      intro="Estas son las reglas para usar P.A.R.C.E. Al crear tu cuenta las aceptas, junto con la Política de tratamiento de datos personales."
      other={{ to: '/privacidad', label: 'Política de tratamiento de datos personales' }}
    >
      <section>
        <h2>1. Qué es P.A.R.C.E</h2>
        <p>
          P.A.R.C.E es una plataforma que conecta a conductores que tienen una emergencia con su vehículo con mecánicos verificados
          que están cerca: batería, llantas, combustible, fallas mecánicas o grúa.
        </p>
      </section>

      <section>
        <h2>2. Tu cuenta</h2>
        <ul>
          <li>Debes ser mayor de edad y dar información verdadera y actualizada.</li>
          <li>La cuenta es personal. Cuida tu contraseña: lo que se haga con tu sesión queda a tu nombre.</li>
          <li>Puedes actualizar tus datos en cualquier momento desde Mi perfil.</li>
        </ul>
      </section>

      <section>
        <h2>3. Solicitudes de asistencia</h2>
        <ul>
          <li>Al pedir asistencia compartes la ubicación de la emergencia, el vehículo y la descripción de la falla.</li>
          <li>Cada tipo de servicio tiene un precio base de referencia. El costo final lo registra el mecánico al terminar e incluye los repuestos que haya usado.</li>
          <li>Puedes cancelar una solicitud desde la app indicando el motivo.</li>
        </ul>
      </section>

      <section>
        <h2>4. Mecánicos</h2>
        <ul>
          <li>Para atender solicitudes debes ser aprobado por un administrador. Cada servicio se aprueba por separado y solo verás las solicitudes de los servicios que tengas aprobados.</li>
          <li>Necesitas tu licencia de conducción vigente; si se vence, no podrás aceptar solicitudes hasta actualizarla.</li>
          <li>Los documentos y certificados que subas deben ser verdaderos. Presentar documentos falsos es motivo para suspender la cuenta.</li>
          <li>Debes tratar con respeto al conductor, informarle el costo y registrar en la app los repuestos y la evidencia del trabajo.</li>
        </ul>
      </section>

      <section>
        <h2>5. Pagos</h2>
        <p>
          El pago se hace al terminar el servicio con los métodos que muestre la app. Si pagas en efectivo, el mecánico confirma en la
          app que lo recibió. El recibo queda disponible en tu historial.
        </p>
      </section>

      <section>
        <h2>6. Calificaciones, encuestas y PQR</h2>
        <ul>
          <li>Conductor y mecánico pueden calificarse al terminar. Se muestra el promedio; quien recibe la calificación no ve el detalle de cada una.</li>
          <li>Tus peticiones, quejas, reclamos y sugerencias se atienden desde la sección PQR.</li>
          <li>Escribe con respeto: no se permiten insultos, amenazas ni información falsa.</li>
        </ul>
      </section>

      <section>
        <h2>7. Uso indebido</h2>
        <p>
          No está permitido suplantar a otra persona, crear solicitudes falsas, intentar acceder a cuentas o datos ajenos ni afectar el
          funcionamiento de la plataforma. Podemos suspender las cuentas que lo hagan.
        </p>
      </section>

      <section>
        <h2>8. Emergencias y responsabilidad</h2>
        <ul>
          <li><strong>Si hay personas heridas o en peligro, llama primero a la línea de emergencias 123.</strong> P.A.R.C.E atiende fallas del vehículo, no emergencias médicas.</li>
          <li>Hacemos lo posible para que siempre haya un mecánico disponible, pero el tiempo de llegada depende de la ubicación y de los mecánicos cercanos.</li>
          <li>El trabajo lo realiza el mecánico que acepta la solicitud. Si tienes un problema con un servicio, cuéntanos por PQR y lo revisamos.</li>
        </ul>
      </section>

      <section>
        <h2>9. Datos personales</h2>
        <p>
          Tratamos tus datos según la <Link to="/privacidad">Política de tratamiento de datos personales</Link>, que explica qué datos
          usamos, para qué y cómo ejercer tus derechos.
        </p>
      </section>

      <section>
        <h2>10. Cambios y ley aplicable</h2>
        <p>
          Si cambiamos estos términos, publicaremos la nueva versión aquí y te pediremos aceptarla al entrar a la app. Estos términos
          se rigen por las leyes de Colombia. Para cualquier duda escríbenos a <a href={`mailto:${CONTACT_EMAIL}`}>{CONTACT_EMAIL}</a>.
        </p>
      </section>
    </LegalLayout>
  );
}
