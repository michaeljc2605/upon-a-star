<?php
declare(strict_types=1);
require_once __DIR__ . '/_start.php';
require_once __DIR__ . '/../base/includes/layout.php';
page_header('Privacy');
?>
<main class="section booking-page policy-page">
  <div class="heading"><div><p class="eyebrow">Upon a Star Cinema</p><h1>Your <em>privacy.</em></h1><span>How this student cinema project uses information when you try its features.</span></div></div>
  <article class="panel booking-panel">
    <h2>Information you provide</h2>
    <p>Google sign-in supplies your account identifier, name and email address. Your profile stores your chosen display name and date of birth. Reservations store your name, email, phone number, screening, seats and booking total. This project does not collect payment-card details or process payments.</p>
    <h2>Why we use it</h2>
    <p>Your details connect your profile to reservations and tickets. Authorized cinema staff can look up bookings and scan tickets; owners can manage accounts and screenings. Session and audit records may include your IP address, browser information and actions to support access control and troubleshooting.</p>
    <h2>Cookies and service providers</h2>
    <p>Essential cookies keep you signed in, protect forms and remember an in-progress booking. Google handles sign-in. The hosting provider stores the site and database and may maintain request logs. When booking email is configured, EmailJS and the connected email provider receive the recipient and reservation details needed to deliver it. Email ticket images use QRServer, which receives the ticket code to generate the QR image; keep ticket codes private.</p>
    <h2>Your choices</h2>
    <p>You can browse films without signing in, edit your profile and sign out. Removing site cookies ends the browser's saved sign-in state. Account and booking records are not automatically deleted when you sign out, and the project has no fixed automatic deletion schedule. Contact the maintainer to request access, correction or deletion of your information.</p>
    <h2>Project contact</h2>
    <p>This IE4727 project was created by Celine Emily Wijaya and Michael Joseph Candra. For this hosted copy, contact <a href="mailto:Michaelj005@e.ntu.edu.sg">Michael Joseph Candra</a>. Please do not email passwords or payment details.</p>
  </article>
</main>
<?php page_footer(); ?>
