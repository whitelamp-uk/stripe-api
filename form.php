
            <style>
<?php require __DIR__.'/client.css'; ?>
            </style>

            <form id="payment-form">

<?php if (defined('STRIPE_DEV_MODE') && STRIPE_DEV_MODE): ?>
              <table>
                <tr><td>A: Payment succeeds</td><td>4000 0582 6000 0005</td></tr>
                <tr><td>B: Payment requires authentication</td><td>4000 0025 0000 3155</td></tr>
                <tr><td>C: Payment is declined</td><td>4000 0000 0000 9995</td></tr>
                <tr><td colspan="2"><small>For A, use UK postcode. For B &amp; C use ZIP ("12345" or similar).</small></td></tr>
              </table>
<?php endif; ?>

              <div id="card-element"><!--client.js injects the card element--></div>

              <button id="submit">
                <div class="spinner hidden" id="spinner"></div>
                <span id="button-text">Pay now</span>
              </button>

              <p id="card-error" role="alert"></p>

              <p class="old-result-message hidden">
                Payment succeeded, see the result in your
                <a href="" target="_blank">Stripe dashboard.</a> Refresh the page to pay again.
              </p>

              <div class="result-message-ok hidden">
                <p><?php echo htmlspecialchars ($this->org['signup_done_message_ok']); ?></p>
                <div>Your payment reference is: <?php echo STRIPE_CODE.'-'.$newid; ?></div>
              </div>

              <div class="result-message-fail hidden">
                <p><?php echo $this->org['signup_done_message_fail']; ?></p>
                <div>Click <a href="./tickets.php">here</a> to try again</div>
              </div>

            </form>

            <script type="text/javascript">

                var stripe = Stripe('<?php echo STRIPE_PUBLIC_KEY ?>');
                var clientSecret = "<?php echo $intent->client_secret ?>";
                var postcode = "<?php echo $v['postcode'] ?>";
                console.log ("in form.php postcode is " + postcode);
                // var purchase = { items: [ {id:"xl-tshirt"} ] };

<?php require __DIR__.'/client.js'; ?>

            </script>

