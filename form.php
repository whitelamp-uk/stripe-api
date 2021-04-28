
            <form id="payment-form">
            <?php if (defined('STRIPE_DEV_MODE') && STRIPE_DEV_MODE) { ?>
                <table>
                <tr><td>Payment succeeds</td><td>4000 0582 6000 0005</td></tr>
                <tr><td>Payment requires authentication</td><td>4000 0025 0000 3155</td></tr>
                <tr><td>Payment is declined</td><td>4000 0000 0000 9995</td></tr>
                </table>
            <?php } ?>

              <div id="card-element"><!--Stripe.js injects the Card Element--></div>
              <button id="submit">
                <div class="spinner hidden" id="spinner"></div>
                <span id="button-text">Pay now</span>
              </button>
              <p id="card-error" role="alert"></p>
              <p class="result-message hidden">
                Payment succeeded, see the result in your
                <a href="" target="_blank">Stripe dashboard.</a> Refresh the page to pay again.
              </p>
            </form>

            <script type="text/javascript">
                var stripe = Stripe('<?php echo STRIPE_PUBLIC_KEY ?>');
                var clientSecret = "<?php echo $intent->client_secret ?>";
                var postcode = "<?php echo $_POST['postcode'] ?>";
                console.log ("in form.php postcode is " + postcode);
                var purchase = {
                  items: [{ id: "xl-tshirt" }]
                };
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

