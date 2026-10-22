import { Redirect } from 'expo-router'
import { flags } from '@gs/core/lib'
import PetScreen from '@/features/profile/screens/PetScreen'

/** A pet's screen while pets are in the interface (flags.householdPets); off (M57 §6), any way in goes back to Profile. */
export default function PetRoute() {
  return flags.householdPets ? <PetScreen /> : <Redirect href="/(tabs)/profile" />
}
