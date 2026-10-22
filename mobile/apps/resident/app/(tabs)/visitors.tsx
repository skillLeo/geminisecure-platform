import VisitorsScreen from '@/features/visitors/screens/VisitorsScreen'
import { withLevel } from '@/features/access/LevelGate'

// Visitor passes are issued by Members (`access.may.visitor_passes`); another level keeps its own e-pass, one tap away.
export default withLevel('visitor_passes', 'Visitors', VisitorsScreen, { epass: true })
