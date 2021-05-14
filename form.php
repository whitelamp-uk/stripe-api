
            <form id="payment-form">
            <?php if (defined('STRIPE_DEV_MODE') && STRIPE_DEV_MODE) { ?>
                <table>
                <tr><td>A: Payment succeeds</td><td>4000 0582 6000 0005</td></tr>
                <tr><td>B: Payment requires authentication</td><td>4000 0025 0000 3155</td></tr>
                <tr><td>C: Payment is declined</td><td>4000 0000 0000 9995</td></tr>
                <tr><td colspan="2"><small>For A, use UK postcode. For B &amp; C use ZIP ("12345" or similar).</small></td></tr>
                </table>
            <?php } ?>

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
              <p class="result-message hidden">
                <?php echo $this->org['signup_done_message']; ?>
              </p>
            </form>

            <script type="text/javascript">
                var stripe = Stripe('<?php echo STRIPE_PUBLIC_KEY ?>');
                var clientSecret = "<?php echo $intent->client_secret ?>";
                var postcode = "<?php echo $v['postcode'] ?>";
                console.log ("in form.php postcode is " + postcode);
                //var purchase = {                  items: [{ id: "xl-tshirt" }]                };
                <?php require __DIR__.'/client.js'; ?>
                /*var elements = stripe.elements();
                var style = {
                  base: {
                    color: "#32325d",
                  }
                };
                var card = elements.create("card", { style: style });
                card.mount("#card-element");*/

            </script>

