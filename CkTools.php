<?php
session_start();

require 'config.php';

// Restrict access to UserID 1.
// IMPORTANT: run the auth check BEFORE including header.php. header.php emits
// HTML output immediately, and once any output is sent the header("Location: ...")
// redirect below fails ("headers already sent") and the restricted content would
// still render to an unauthorized user.
if (!isset($_SESSION['userID']) || $_SESSION['userID'] != 1) {
    header("Location: 403.php");
    exit();
}

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CK Tools</title>
    
</head>
<body>
    <div class="container my-4">
        <!-- Tabs -->
        <ul class="nav nav-tabs" id="tabs">
            <li class="nav-item">
                <a class="nav-link active" href="#goals" data-bs-toggle="tab">Goals</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#risk-assessment" data-bs-toggle="tab">Risk Assessment</a>
            </li>
        </ul>

        <div class="tab-content mt-4">
            <!-- Goals Tab -->
            <div class="tab-pane fade show active" id="goals">
                <h2>Your Goals</h2>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Tier 1</th>
                                <th>Improve Mental Health</th>
                                <th>Improve Physical Health</th>
                                <th>Career Development</th>
                                <th>Life Development</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Ultimate Goal</td>
                                <td>Ultimate goal to reduce anxiety and worrying and improve overall wellbeing & motivation.</td>
                                <td>Enjoy working out, become physically fitter & stronger. Introduction of workouts to improve mental health.</td>
                                <td>Improve professional skillset, take the step into Leadership and establish own professional goals.</td>
                                <td>Become more adulty, own property.</td>
                            </tr>
                            <tr>
                                <td>Tier 2s</td>
                                <td>
                                    <ul>
                                        <li>Reduce screentime around bedtime; if required, it's positive, not doom scrolling. No bed rotting. Bed Times. Routine.</li>
                                        <li>Documenting worries and risk assessing at night.</li>
                                        <li>Closing Exercise Rings on the majority of days. Minimum 20 mins of exercise daily.</li>
                                        <li>If you're WFH, you're up and showered at least 20 mins before starting. Only WFH if there is a desk—no "hybrid working from the bedroom office."</li>
                                        <li>Structured Weekly Routine, set days in Chorlton / Set Days at Home TBC. Plan in Tyche in advance.</li>
                                        <li>Saying No to events where it doesn't suit you. Only say yes if you actually want to do it.</li>
                                        <li>Being more social, pub after explorers.</li>
                                        <li>Agreeing Police Duties in advance with DC, good diary management.</li>
                                        <li>Ensuring DC's goals and mental health is good. Happy DC = Happy CK.</li>
                                    </ul>
                                </td>
                                <td>
                                    <ul>
                                        <li>Diet, keeping fatty foods to a minimum. Start to make packed lunches for work.</li>
                                        <li>Drinking more water, staying hydrated.</li>
                                        <li>Focus on the "better body" journey, not the "better body" destination.</li>
                                        <li>Improve hygiene routines. Regularly shaving and moisturizing skin.</li>
                                    </ul>
                                </td>
                                <td>
                                    <ul>
                                        <li>Suits for the office unless it's casual Fridays.</li>
                                        <li>Shadowing CISO & Office Days, more management tasks.</li>
                                        <li>Good diary management: more focus time, ignore email & Teams messages.</li>
                                        <li>To-do list and weekly priorities, regular check-ins with direct reports.</li>
                                        <li>Send eCards to colleagues for more recognition in Cyber.</li>
                                        <li>Instead of asking for advice from senior colleagues, tell them the solution and do it without asking if no approval is required.</li>
                                        <li>Attend more conferences / professional development events.</li>
                                        <li>Maintain the number of public speaking events, improve the way you speak.</li>
                                    </ul>
                                </td>
                                <td>
                                    <ul>
                                        <li>Minimum Spend January.</li>
                                        <li>Buy a property (New Year is the time).</li>
                                        <li>Regular life goal meetings with DC (minimum frequency suggested bi-monthly). Set agenda.</li>
                                        <li>Plan meals; don't buy food on the night.</li>
                                    </ul>
                                </td>
                            </tr>
                            <tr>
                                <td>Metrics to Measure Success</td>
                                <td>
                                    <ul>
                                        <li>You have fewer panic attacks.</li>
                                        <li>You generally feel more positive and have a good attitude.</li>
                                    </ul>
                                </td>
                                <td>
                                    <ul>
                                        <li>You have increased weight.</li>
                                        <li>You feel more content with body image.</li>
                                        <li>You are more confident around taking your top off.</li>
                                    </ul>
                                </td>
                                <td>
                                    <ul>
                                        <li>You have achieved a promotion (Grade 5 is the goal).</li>
                                        <li>Gained CISSP certification by the end of FY26.</li>
                                        <li>Spoken at least twice at a professional conference.</li>
                                    </ul>
                                </td>
                                <td>
                                    <ul>
                                        <li>You own the house.</li>
                                        <li>You feel comfortable in the house and in financial means.</li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Risk Assessment Tab -->
            <div class="tab-pane fade" id="risk-assessment">
                <h2>Risk Assessment</h2>
                <form>
                    <div class="mb-3">
                        <label for="worry" class="form-label">What's your worry?</label>
                        <input type="text" class="form-control" id="worry" placeholder="Describe your worry">
                    </div>
                    <div class="mb-3">
                        <label for="impact" class="form-label">Impact (1-5)</label>
                        <select class="form-select" id="impact">
                            <option value="1">1 - Minimal</option>
                            <option value="2">2 - Low Impact</option>
                            <option value="3">3 - Moderate Impact</option>
                            <option value="4">4 - High Impact</option>
                            <option value="5">5 - Severe</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="impact-description" class="form-label">Describe the impact</label>
                        <textarea class="form-control" id="impact-description" rows="3" placeholder="What will happen if this occurs?"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="likelihood" class="form-label">Likelihood (1-5)</label>
                        <select class="form-select" id="likelihood">
                            <option value="1">1 - Highly Unlikely</option>
                            <option value="2">2 - Rare</option>
                            <option value="3">3 - Possible</option>
                            <option value="4">4 - Likely</option>
                            <option value="5">5 - Almost Certain</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="likelihood-description" class="form-label">Describe the likelihood</label>
                        <textarea class="form-control" id="likelihood-description" rows="3" placeholder="Why do you think this is likely/unlikely?"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="evidence" class="form-label">What evidence do you have?</label>
                        <textarea class="form-control" id="evidence" rows="3" placeholder="What evidence supports or refutes this worry?"></textarea>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="assessRisk()">Assess Risk</button>
                </form>
                <div id="result" class="alert mt-3 d-none"></div>
                <div id="calming-tips" class="alert alert-info mt-3 d-none"></div>
            </div>
        </div>
    </div>

    <script>
        function assessRisk() {
            const worry = document.getElementById('worry').value.trim();
            const impact = parseInt(document.getElementById('impact').value);
            const likelihood = parseInt(document.getElementById('likelihood').value);
            const impactDesc = document.getElementById('impact-description').value.trim();
            const likelihoodDesc = document.getElementById('likelihood-description').value.trim();
            const evidence = document.getElementById('evidence').value.trim();

            if (!worry || isNaN(impact) || isNaN(likelihood) || !impactDesc || !likelihoodDesc || !evidence) {
                alert('Please fill in all required fields.');
                return;
            }

            const riskScore = impact * likelihood;
            const resultDiv = document.getElementById('result');
            const tipsDiv = document.getElementById('calming-tips');

            let riskLevel = '';
            let alertClass = '';

            if (riskScore <= 8) {
                riskLevel = 'Low Risk - Very unlikely to cause issues.';
                alertClass = 'alert-success';
            } else if (riskScore <= 12) {
                riskLevel = 'Moderate Risk - Manageable with awareness.';
                alertClass = 'alert-warning';
            } else {
                riskLevel = 'High Risk - Consider addressing, but avoid overreaction.';
                alertClass = 'alert-danger';
            }

            resultDiv.innerHTML = `
                <p><strong>Worry:</strong> ${worry}</p>
                <p><strong>Risk Score:</strong> ${riskScore}</p>
                <p><strong>Risk Level:</strong> ${riskLevel}</p>
                <p><strong>Impact Description:</strong> ${impactDesc}</p>
                <p><strong>Likelihood Description:</strong> ${likelihoodDesc}</p>
                <p><strong>Evidence:</strong> ${evidence}</p>
            `;
            resultDiv.className = `alert ${alertClass} mt-3`;
            resultDiv.classList.remove('d-none');

            let tips = '';
            if (riskScore <= 8) {
                tips = 'This worry seems very unlikely to cause harm. Focus on positive outcomes and take a deep breath.';
            } else if (riskScore <= 12) {
                tips = 'This worry is moderate. Break the problem into smaller steps and focus on what you can control.';
            } else {
                tips = 'This worry may seem big, but ask yourself: "Is this a short-term issue or a long-term concern?" Focus on actionable steps.';
            }

            tipsDiv.textContent = tips;
            tipsDiv.classList.remove('d-none');
        }
    </script>
</body>
</html>
<?php include 'footer.php'; ?>
