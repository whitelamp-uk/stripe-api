
    <form class="signup" onsubmit="return false">

      <fieldset class="finished">

        <legend>Thank you</legend>

        <div>

          <h3>Sign-up received successfully</h3>

          <p>Thank you for your support!</p>

          <p>Sign-up reference: <span class="signup reference"><?php echo htmlspecialchars ($signup_id); ?></span></p>

          <p>An email confirming your sign-up has been sent to <?php echo htmlspecialchars ($_POST['email']); ?></p>

        </div>

      </fieldset>

    </form>

