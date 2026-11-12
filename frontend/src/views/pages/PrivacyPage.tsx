import { Link } from 'react-router-dom';
import LegalLayout from '../components/LegalLayout';
import { CONTACT_EMAIL } from '../legal';

/**
 * Política de tratamiento de datos personales (Ley 1581 de 2012 y Decreto
 * 1377 de 2013, compilado en el Decreto 1074 de 2015). Describe lo que la app
 * hace hoy: si cambia lo que se recolecta o para qué, se actualiza este texto
 * y se sube POLICY_VERSION (views/legal.ts) junto con el backend.
 */
export default function PrivacyPage() {
  return (
    <LegalLayout
      title="Política de tratamiento de datos personales"
      intro="En P.A.R.C.E tratamos tus datos personales para conectarte con un mecánico cuando tu vehículo tiene una emergencia. Aquí te contamos qué datos usamos, para qué, con quién los compartimos y cómo ejercer tus derechos, según la Ley 1581 de 2012."
      other={{ to: '/terminos', label: 'Términos y condiciones' }}
    >
      <section>
        <h2>1. Responsable del tratamiento</h2>
        <p>
          P.A.R.C.E — Plataforma de Asistencia Rápida para Conductores en Emergencias. Correo: <a href={`mailto:${CONTACT_EMAIL}`}>{CONTACT_EMAIL}</a>.
          Dentro de la app también puedes escribirnos desde la sección PQR.
        </p>
      </section>

      <section>
        <h2>2. Qué datos tratamos</h2>
        <ul>
          <li><strong>Identificación y contacto:</strong> nombre, cédula, correo y teléfono.</li>
          <li><strong>Licencia de conducción:</strong> número, fecha de vencimiento y, si decides subirla, su foto.</li>
          <li><strong>Vehículos:</strong> placa, marca, modelo, año, color, SOAT y revisión técnico-mecánica.</li>
          <li><strong>Ubicación:</strong> el punto donde pides asistencia, solo cuando creas una solicitud.</li>
          <li><strong>El servicio:</strong> descripción de la falla, fotos de evidencia, repuestos, costo, pagos (método y monto; de una tarjeta solo los últimos cuatro dígitos), calificaciones, encuestas y PQR.</li>
          <li><strong>Mecánicos:</strong> certificados, experiencia y los soportes de cada servicio que solicitan prestar.</li>
          <li><strong>Seguridad:</strong> fechas de acceso, dirección IP y navegador, para proteger tu cuenta.</li>
          <li><strong>Asistente de diagnóstico:</strong> los mensajes que le escribes.</li>
        </ul>
      </section>

      <section>
        <h2>3. Para qué los usamos</h2>
        <ul>
          <li>Crear y administrar tu cuenta.</li>
          <li>Encontrar un mecánico cercano y prestarte la asistencia que pediste.</li>
          <li>Verificar a los mecánicos: su licencia, sus certificados y los servicios que pueden prestar.</li>
          <li>Registrar pagos y emitir recibos.</li>
          <li>Atender tus PQR y usar las calificaciones y encuestas para mejorar el servicio.</li>
          <li>Enviarte correos del servicio, como la recuperación de contraseña o alertas de seguridad.</li>
          <li>Proteger la plataforma contra fraudes y accesos indebidos, y cumplir obligaciones legales.</li>
        </ul>
        <p className="mt-3">No vendemos tus datos ni los usamos para publicidad de terceros.</p>
      </section>

      <section>
        <h2>4. Con quién los compartimos</h2>
        <ul>
          <li>
            <strong>El mecánico que atiende tu solicitud:</strong> antes de aceptarla solo ve una ubicación aproximada y los datos del vehículo;
            al aceptarla ve tu nombre, tu teléfono y la ubicación exacta para poder llegar.
          </li>
          <li><strong>Los administradores de P.A.R.C.E,</strong> para revisar solicitudes de mecánicos, pagos y PQR.</li>
          <li>
            <strong>Proveedores tecnológicos</strong> que procesan datos por cuenta nuestra: alojamiento de la app y de la base de datos,
            envío de correos, verificación contra robots y el asistente de diagnóstico con inteligencia artificial. Algunos tienen sus
            servidores fuera de Colombia, por ejemplo en Estados Unidos.
          </li>
          <li><strong>Autoridades,</strong> solo cuando la ley lo exija.</li>
        </ul>
      </section>

      <section>
        <h2>5. Tus derechos</h2>
        <p>Como titular de los datos puedes:</p>
        <ul>
          <li>Conocer, actualizar y rectificar tus datos.</li>
          <li>Pedir prueba de la autorización que nos diste.</li>
          <li>Saber cómo hemos usado tus datos.</li>
          <li>Revocar la autorización o pedir que eliminemos tus datos, salvo los que la ley nos obligue a conservar.</li>
          <li>Acceder gratis a tus datos.</li>
          <li>Presentar quejas ante la Superintendencia de Industria y Comercio (<a href="https://www.sic.gov.co" target="_blank" rel="noreferrer">sic.gov.co</a>) después de haber hecho tu consulta o reclamo ante nosotros.</li>
        </ul>
      </section>

      <section>
        <h2>6. Cómo ejercerlos</h2>
        <ul>
          <li>Tus datos personales, tu licencia y tus vehículos los puedes actualizar tú mismo desde <strong>Mi perfil</strong>.</li>
          <li>Para consultas, reclamos, revocar la autorización o pedir que eliminemos tus datos, escríbenos por <strong>PQR</strong> en la app o al correo <a href={`mailto:${CONTACT_EMAIL}`}>{CONTACT_EMAIL}</a>.</li>
          <li>Respondemos las consultas en máximo 10 días hábiles (prorrogables 5 más) y los reclamos en máximo 15 días hábiles (prorrogables 8 más), como indica la ley.</li>
        </ul>
      </section>

      <section>
        <h2>7. Datos sensibles y menores de edad</h2>
        <p>
          No pedimos datos sensibles. La foto de tu licencia es opcional. P.A.R.C.E es para personas mayores de edad.
        </p>
      </section>

      <section>
        <h2>8. Cómo protegemos tus datos</h2>
        <ul>
          <li>Las contraseñas se guardan cifradas (Argon2id); nadie puede leerlas, ni siquiera nosotros.</li>
          <li>La conexión con la app viaja cifrada (HTTPS).</li>
          <li>Cada persona solo ve lo que le corresponde según su rol.</li>
          <li>Bloqueamos la cuenta temporalmente ante varios intentos fallidos de inicio de sesión y te avisamos por correo.</li>
        </ul>
      </section>

      <section>
        <h2>9. Cuánto tiempo los conservamos</h2>
        <p>
          Mientras tengas tu cuenta. Si pides eliminarla, borramos o anonimizamos tus datos, salvo los que debamos conservar
          por obligación legal o contable, como los registros de pagos.
        </p>
      </section>

      <section>
        <h2>10. Vigencia y cambios</h2>
        <p>
          Esta política rige desde la fecha indicada arriba. Si la cambiamos, publicaremos la nueva versión aquí y te pediremos
          aceptarla al entrar a la app. Al crear tu cuenta aceptas esta política junto con los <Link to="/terminos">Términos y condiciones</Link>.
        </p>
      </section>
    </LegalLayout>
  );
}
